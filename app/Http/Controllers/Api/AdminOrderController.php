<?php

namespace App\Http\Controllers\Api;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * مدیریت سفارش‌ها در پنل مدیریت.
 *
 * وضعیت سفارش فقط در مسیرهای مجاز جلو می‌رود تا گزارش فروش و تاریخچهٔ
 * ارسال قابل اعتماد بماند. لغو سفارش پرداخت در محل، موجودی اقلام را
 * یک‌بار به انبار برمی‌گرداند.
 */
class AdminOrderController extends AdminController
{
    private const STATUSES = ['pending', 'paid', 'processing', 'shipped', 'delivered', 'cancelled'];

    /** وضعیت‌های مقصد مجاز برای هر وضعیت جاری. */
    private const TRANSITIONS = [
        'pending' => ['paid', 'cancelled'],
        'paid' => ['processing', 'cancelled'],
        'processing' => ['shipped', 'cancelled'],
        'shipped' => ['delivered'],
        'delivered' => [],
        'cancelled' => [],
    ];

    public function index(Request $request): JsonResponse
    {
        $query = Order::query()->withCount('items');

        if ($q = trim((string) $request->query('q', ''))) {
            $query->where(function (Builder $inner) use ($q) {
                $inner->where('order_number', 'like', "%{$q}%")
                    ->orWhere('customer_phone', 'like', "%{$q}%")
                    ->orWhere('customer_first_name', 'like', "%{$q}%")
                    ->orWhere('customer_last_name', 'like', "%{$q}%")
                    ->orWhere('tracking_code', 'like', "%{$q}%");
            });
        }

        if ($status = (string) $request->query('status', '')) {
            if (in_array($status, self::STATUSES, true)) {
                $query->where('status', $status);
            }
        }

        if ($payment = (string) $request->query('paymentMethod', '')) {
            if (in_array($payment, ['cod', 'online', 'installment'], true)) {
                $query->where('payment_method', $payment);
            }
        }

        if ($from = (string) $request->query('from', '')) {
            if (strtotime($from) !== false) {
                $query->whereDate('created_at', '>=', $from);
            }
        }

        if ($to = (string) $request->query('to', '')) {
            if (strtotime($to) !== false) {
                $query->whereDate('created_at', '<=', $to);
            }
        }

        $total = (clone $query)->count();
        $perPage = $this->perPage($request);
        $page = $this->page($request);

        $orders = $query->orderByDesc('id')->forPage($page, $perPage)->get();

        return response()->json([
            'ok' => true,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'pageCount' => max((int) ceil($total / $perPage), 1),
            'items' => $orders->map(fn (Order $o) => $this->serialize($o))->all(),
        ]);
    }

    public function show(Order $order): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'order' => $this->serialize($order->load('items', 'user'), true),
        ]);
    }

    public function update(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(self::STATUSES)],
            'trackingCode' => ['sometimes', 'nullable', 'string', 'max:60'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $result = DB::transaction(function () use ($request, $order, $data) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $updates = [];
            $changes = [];
            $restocked = false;

            if (isset($data['status']) && $data['status'] !== $locked->status) {
                $next = $data['status'];
                $allowed = self::TRANSITIONS[$locked->status] ?? [];

                if (! in_array($next, $allowed, true)) {
                    return response()->json([
                        'ok' => false,
                        'error' => "تغییر وضعیت از «{$locked->status}» به «{$next}» مجاز نیست.",
                    ], 422);
                }

                $changes['status'] = ['from' => $locked->status, 'to' => $next];
                $updates['status'] = $next;

                if ($next === 'paid') {
                    $updates['paid_at'] = $locked->paid_at ?? now();
                }
                if ($next === 'shipped') {
                    $updates['shipped_at'] = now();
                }
                if ($next === 'delivered') {
                    $updates['delivered_at'] = now();
                    // تحویل سفارش پرداخت در محل یعنی پول دریافت شده است.
                    if ($locked->payment_method === 'cod' && ! $locked->paid_at) {
                        $updates['paid_at'] = now();
                    }
                }
                if ($next === 'cancelled') {
                    $updates['cancelled_at'] = now();
                    // فقط سفارش پرداخت در محل هنگام ثبت، موجودی را کم کرده است.
                    if ($locked->payment_method === 'cod' && ! $locked->cancelled_at) {
                        foreach ($locked->items()->get() as $item) {
                            Product::query()
                                ->where('slug', $item->product_slug)
                                ->increment('stock', $item->quantity);
                        }
                        $restocked = true;
                    }
                }
            }

            if (array_key_exists('trackingCode', $data)) {
                $updates['tracking_code'] = $data['trackingCode'] !== '' ? $data['trackingCode'] : null;
            }

            if (array_key_exists('note', $data)) {
                $updates['note'] = $data['note'] !== '' ? $data['note'] : null;
            }

            if ($updates !== []) {
                $locked->update($updates);
            }

            $this->audit($request, 'order.updated', 'order', $locked->id, $changes + [
                'restocked' => $restocked,
                'fields' => array_keys($updates),
            ]);

            return response()->json([
                'ok' => true,
                'order' => $this->serialize($locked->fresh()->load('items', 'user'), true),
            ]);
        });

        return $result;
    }

    private function serialize(Order $o, bool $detailed = false): array
    {
        $payload = [
            'id' => $o->id,
            'orderNumber' => $o->order_number,
            'status' => $o->status,
            'paymentMethod' => $o->payment_method,
            'shippingMethod' => $o->shipping_method,
            'total' => $o->total,
            'subtotal' => $o->subtotal,
            'discount' => $o->discount,
            'shipping' => $o->shipping,
            'couponCode' => $o->coupon_code,
            'customerName' => $o->customerName(),
            'customerPhone' => $o->customer_phone,
            'itemCount' => $o->items_count ?? null,
            'trackingCode' => $o->tracking_code,
            'createdAt' => optional($o->created_at)->toIso8601String(),
            'paidAt' => optional($o->paid_at)->toIso8601String(),
            'shippedAt' => optional($o->shipped_at)->toIso8601String(),
            'deliveredAt' => optional($o->delivered_at)->toIso8601String(),
            'cancelledAt' => optional($o->cancelled_at)->toIso8601String(),
        ];

        if (! $detailed) {
            return $payload;
        }

        return $payload + [
            'customerEmail' => $o->customer_email,
            'shippingAddress' => [
                'province' => $o->shipping_province,
                'city' => $o->shipping_city,
                'line' => $o->shipping_line,
                'postalCode' => $o->shipping_postal_code,
            ],
            'note' => $o->note,
            'user' => $o->user ? [
                'id' => $o->user->id,
                'phone' => $o->user->phone,
                'fullName' => $o->user->fullName(),
            ] : null,
            'payment' => [
                'refId' => $o->payment_ref_id,
                'cardPan' => $o->payment_card_pan,
            ],
            'items' => $o->items->map(fn ($item) => [
                'slug' => $item->product_slug,
                'name' => $item->product_name,
                'unitPrice' => $item->unit_price,
                'quantity' => $item->quantity,
                'lineTotal' => $item->line_total,
            ])->all(),
        ];
    }
}
