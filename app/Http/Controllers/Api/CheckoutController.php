<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Services\OnlinePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CheckoutController extends Controller
{
    /** حداقل مبلغ سفارش برای خرید اقساطی (تومان) */
    private const INSTALLMENT_MIN_TOTAL = 30_000_000;

    public function __construct(private readonly OnlinePaymentService $payments) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.slug' => ['required', 'string'],
            'items.*.quantity' => ['required', 'integer', 'between:1,20'],
            'firstName' => ['required', 'string', 'max:60'],
            'lastName' => ['required', 'string', 'max:60'],
            'phone' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:120'],
            'province' => ['required', 'string', 'max:60'],
            'city' => ['required', 'string', 'max:60'],
            'line' => ['required', 'string', 'max:500'],
            'postalCode' => ['nullable', 'string', 'max:20'],
            'paymentMethod' => ['required', 'in:cod,online,installment'],
            'shippingMethod' => ['nullable', 'string', 'max:40'],
            'couponCode' => ['nullable', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:500'],
            'idempotencyKey' => ['nullable', 'string', 'max:80'],
        ]);

        $user = $request->user();

        /*
         * کلید idempotency از مرورگر می‌آید و قابل حدس است. پاسخ تکراری
         * فقط وقتی برگردانده می‌شود که همان کاربر (یا مهمان با همان شماره)
         * صاحب سفارش باشد؛ وگرنه درخواست تازه پردازش می‌شود.
         */
        if (! empty($data['idempotencyKey'])) {
            $query = Order::query()->where('idempotency_key', $data['idempotencyKey']);

            if ($user) {
                $query->where('user_id', $user->id);
            } else {
                $query->whereNull('user_id')->where('customer_phone', $data['phone']);
            }

            $existing = $query->first();

            if ($existing) {
                return response()->json([
                    'ok' => true,
                    'order' => $this->payload($existing->load('items')),
                    'payment' => $this->paymentIntent($existing),
                    'duplicate' => true,
                ]);
            }

            // کلید یکتاست؛ اگر به سفارش دیگری تعلق دارد، به‌جای خطای دیتابیس
            // یا افشای آن سفارش، تعارض صریح برگردانده می‌شود.
            if (Order::where('idempotency_key', $data['idempotencyKey'])->exists()) {
                return response()->json([
                    'ok' => false,
                    'error' => 'این کلید درخواست قبلاً برای سفارش دیگری استفاده شده است.',
                ], 409);
            }
        }

        $created = DB::transaction(function () use ($data, $user) {
            $lines = [];
            $subtotal = 0;

            foreach ($data['items'] as $row) {
                $product = Product::query()->active()->where('slug', $row['slug'])->lockForUpdate()->first();

                if (! $product) {
                    return response()->json(['ok' => false, 'error' => "کالای {$row['slug']} یافت نشد."], 422);
                }
                if ($product->stock < $row['quantity']) {
                    return response()->json([
                        'ok' => false,
                        'error' => "موجودی «{$product->name}» کافی نیست.",
                    ], 422);
                }

                $lineTotal = $product->price * $row['quantity'];
                $subtotal += $lineTotal;

                $lines[] = [
                    'product_slug' => $product->slug,
                    'product_name' => $product->name,
                    'unit_price' => $product->price,
                    'quantity' => $row['quantity'],
                    'line_total' => $lineTotal,
                ];
            }

            $discount = 0;
            $couponCode = null;

            if (! empty($data['couponCode'])) {
                $coupon = Coupon::query()
                    ->whereRaw('UPPER(code) = ?', [strtoupper(trim($data['couponCode']))])
                    ->lockForUpdate()
                    ->first();

                if (! $coupon || ! $coupon->isUsableFor($subtotal)) {
                    return response()->json(['ok' => false, 'error' => 'کد تخفیف معتبر نیست یا شرایط آن برقرار نیست.'], 422);
                }

                $discount = (int) floor($subtotal * $coupon->percent / 100);
                $couponCode = $coupon->code;
                $coupon->increment('used_count');
            }

            $freeThreshold = (int) (Setting::where('key', 'shipping.freeThreshold')->value('value') ?? 20000000);
            $shippingCost = (int) (Setting::where('key', 'shipping.cost')->value('value') ?? 450000);
            $shipping = $subtotal >= $freeThreshold ? 0 : $shippingCost;
            $total = max($subtotal - $discount + $shipping, 0);

            if ($data['paymentMethod'] === 'installment' && $total < self::INSTALLMENT_MIN_TOTAL) {
                return response()->json([
                    'ok' => false,
                    'error' => 'برای خرید اقساطی، مبلغ سفارش باید بیشتر باشد.',
                ], 422);
            }

            // پرداخت در محل، سفارش تأییدشده است؛ پرداخت آنلاین/اقساطی تا
            // تأیید تراکنش در وضعیت pending می‌ماند و موجودی را قفل نمی‌کند.
            $isCashOnDelivery = $data['paymentMethod'] === 'cod';

            $order = Order::create([
                'order_number' => $this->nextOrderNumber(),
                'user_id' => $user?->id,
                'status' => $isCashOnDelivery ? 'processing' : 'pending',
                'payment_method' => $data['paymentMethod'],
                'shipping_method' => $data['shippingMethod'] ?? 'standard',
                'coupon_code' => $couponCode,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'shipping' => $shipping,
                'total' => $total,
                'customer_first_name' => $data['firstName'],
                'customer_last_name' => $data['lastName'],
                'customer_phone' => $data['phone'],
                'customer_email' => $data['email'] ?? null,
                'shipping_province' => $data['province'],
                'shipping_city' => $data['city'],
                'shipping_line' => $data['line'],
                'shipping_postal_code' => $data['postalCode'] ?? null,
                'note' => $data['note'] ?? null,
                'idempotency_key' => $data['idempotencyKey'] ?? null,
            ]);

            foreach ($lines as $line) {
                $order->items()->create($line);

                if ($isCashOnDelivery) {
                    Product::where('slug', $line['product_slug'])->decrement('stock', $line['quantity']);
                }
            }

            return $order;
        });

        // خطاهای داخل تراکنش همان پاسخ JSON آماده را برمی‌گردانند.
        if (! $created instanceof Order) {
            return $created;
        }

        /*
         * شروع پرداخت بیرون از تراکنش انجام می‌شود: تماس با درگاه تا ۹ ثانیه
         * طول می‌کشد و نگه‌داشتن قفل ردیف‌های کالا در این مدت، سفارش‌های
         * هم‌زمان را معطل می‌کند.
         */
        $order = $created->load('items');

        return response()->json([
            'ok' => true,
            'order' => $this->payload($order),
            'payment' => $this->paymentIntent($order),
        ], 201);
    }

    /**
     * قصد پرداخت برای پاسخ تسویه.
     *
     * برای پرداخت آنلاین همین‌جا از زرین‌پال authority گرفته می‌شود. اگر درگاه
     * در دسترس نباشد (`redirectUrl` تهی)، متن دقیق دلیل برگردانده می‌شود تا
     * کاربر به‌جای «خطای نامشخص» بداند مشکل کجاست؛ سفارش در هر حال ثبت شده
     * است و از صفحهٔ نتیجه می‌تواند دوباره تلاش کند.
     */
    private function paymentIntent(Order $order): array
    {
        if ($order->payment_method === 'cod') {
            return ['type' => 'cod', 'message' => 'پرداخت هنگام تحویل کالا انجام می‌شود.'];
        }

        if ($order->payment_method === 'online') {
            $started = $this->payments->start($order);

            return $started['ok']
                ? [
                    'type' => 'online',
                    'gateway' => 'zarinpal',
                    'redirectUrl' => $started['redirect_url'],
                    'sandbox' => $started['sandbox'],
                    'message' => null,
                ]
                : [
                    'type' => 'online',
                    'gateway' => 'zarinpal',
                    'redirectUrl' => null,
                    'message' => $started['message'],
                ];
        }

        return [
            'type' => 'installment',
            'redirectUrl' => null,
            'message' => 'پرداخت اقساطی در این نسخه فعال نیست؛ سفارش ثبت شد و کارشناسان نودهی برای هماهنگی تماس می‌گیرند.',
        ];
    }

    private function payload(Order $order): array
    {
        return [
            'id' => $order->id,
            'orderNumber' => $order->order_number,
            'status' => $order->status,
            'paymentMethod' => $order->payment_method,
            'subtotal' => $order->subtotal,
            'discount' => $order->discount,
            'shipping' => $order->shipping,
            'total' => $order->total,
            'couponCode' => $order->coupon_code,
            'customerName' => $order->customerName(),
            'createdAt' => $order->created_at?->toIso8601String(),
            'items' => $order->items->map(fn ($item) => [
                'slug' => $item->product_slug,
                'name' => $item->product_name,
                'unitPrice' => $item->unit_price,
                'quantity' => $item->quantity,
                'lineTotal' => $item->line_total,
            ]),
        ];
    }

    private function nextOrderNumber(): string
    {
        do {
            $number = 'ND-'.now()->format('ymd').'-'.strtoupper(bin2hex(random_bytes(3)));
        } while (Order::where('order_number', $number)->exists());

        return $number;
    }
}
