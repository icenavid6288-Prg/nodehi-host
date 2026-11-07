<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\LoyaltyTier;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * پنل کاربری مشتری.
 *
 * همهٔ مسیرها فقط با توکن معتبر کار می‌کنند و هر رکورد پیش از خواندن یا
 * تغییر، مالکیتش بررسی می‌شود؛ شناسهٔ کاربر هرگز از ورودی گرفته نمی‌شود.
 */
class AccountController extends Controller
{
    private const PAID_STATUSES = ['paid', 'processing', 'shipped', 'delivered'];

    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $orders = Order::query()->where('user_id', $user->id);

        $stats = [
            'orders' => (clone $orders)->count(),
            'pendingOrders' => (clone $orders)->where('status', 'pending')->count(),
            'spent' => (int) (clone $orders)->whereIn('status', self::PAID_STATUSES)->sum('total'),
            'addresses' => $user->addresses()->count(),
            'reviews' => $user->reviews()->count(),
        ];

        $lastOrder = (clone $orders)->with('items')->latest()->first();

        $tiers = LoyaltyTier::query()->active()->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (LoyaltyTier $tier) => [
                'name' => $tier->name,
                'requirement' => $tier->requirement_label,
                'discount' => $tier->discount_label,
                'points' => $tier->points_label,
                'perks' => $tier->perks ?: [],
            ]);

        $currentIndex = $tiers->search(fn (array $tier) => $tier['name'] === $user->loyalty_tier);
        $tiers = $tiers->values();
        // کاربر سطح آخر، سطح بعدی ندارد؛ خواندن ['name'] از null هشدار PHP می‌داد.
        $nextTier = $currentIndex !== false && $currentIndex + 1 < $tiers->count()
            ? ($tiers->get($currentIndex + 1)['name'] ?? null)
            : null;

        return response()->json([
            'ok' => true,
            'user' => $this->userPayload($user),
            'stats' => $stats,
            'lastOrder' => $lastOrder ? $this->orderPayload($lastOrder) : null,
            'loyalty' => [
                'points' => (int) $user->loyalty_points,
                'tier' => $user->loyalty_tier,
                'tiers' => $tiers->all(),
                'nextTier' => $nextTier,
            ],
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'firstName' => ['sometimes', 'nullable', 'string', 'max:60'],
            'lastName' => ['sometimes', 'nullable', 'string', 'max:60'],
            'email' => ['sometimes', 'nullable', 'email', 'max:120', Rule::unique('users', 'email')->ignore($user->id)],
        ], [
            'email.email' => 'ایمیل معتبر نیست.',
            'email.unique' => 'این ایمیل برای حساب دیگری ثبت شده است.',
            'firstName.max' => 'نام حداکثر ۶۰ کاراکتر است.',
        ]);

        foreach (['firstName' => 'first_name', 'lastName' => 'last_name', 'email' => 'email'] as $key => $column) {
            if (array_key_exists($key, $data)) {
                $user->{$column} = $data[$key] === '' ? null : $data[$key];
            }
        }

        $user->save();

        return response()->json([
            'ok' => true,
            'message' => 'اطلاعات حساب ذخیره شد.',
            'user' => $this->userPayload($user),
        ]);
    }

    public function addresses(Request $request): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'items' => $request->user()->addresses()
                ->orderByDesc('is_default')
                ->orderByDesc('id')
                ->get()
                ->map(fn (Address $address) => $this->addressPayload($address))
                ->all(),
            'provinces' => self::PROVINCES,
        ]);
    }

    public function storeAddress(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->addresses()->count() >= 10) {
            return response()->json(['ok' => false, 'error' => 'حداکثر ۱۰ آدرس می‌توانید ثبت کنید.'], 422);
        }

        $data = $this->validatedAddress($request, true);

        $address = DB::transaction(function () use ($user, $data) {
            $isFirst = $user->addresses()->count() === 0;
            $makeDefault = $isFirst || ($data['isDefault'] ?? false);

            if ($makeDefault) {
                $user->addresses()->update(['is_default' => false]);
            }

            return $user->addresses()->create([
                'title' => $data['title'],
                'receiver' => $data['receiver'],
                'phone' => $data['phone'],
                'province' => $data['province'],
                'city' => $data['city'],
                'line' => $data['line'],
                'postal_code' => $data['postalCode'] ?? null,
                'is_default' => $makeDefault,
            ]);
        });

        return response()->json([
            'ok' => true,
            'message' => 'آدرس ثبت شد.',
            'address' => $this->addressPayload($address),
        ], 201);
    }

    public function updateAddress(Request $request, Address $address): JsonResponse
    {
        $this->authorizeAddress($request, $address);

        $data = $this->validatedAddress($request, false);

        DB::transaction(function () use ($address, $data) {
            if (($data['isDefault'] ?? false) === true) {
                $address->user->addresses()->whereKeyNot($address->id)->update(['is_default' => false]);
                $address->is_default = true;
            }

            $address->fill(array_filter([
                'title' => $data['title'] ?? null,
                'receiver' => $data['receiver'] ?? null,
                'phone' => $data['phone'] ?? null,
                'province' => $data['province'] ?? null,
                'city' => $data['city'] ?? null,
                'line' => $data['line'] ?? null,
            ], static fn ($value) => $value !== null && $value !== ''));

            if (array_key_exists('postalCode', $data)) {
                $address->postal_code = $data['postalCode'];
            }

            $address->save();
        });

        return response()->json([
            'ok' => true,
            'message' => 'آدرس به‌روزرسانی شد.',
            'address' => $this->addressPayload($address->fresh()),
        ]);
    }

    public function destroyAddress(Request $request, Address $address): JsonResponse
    {
        $this->authorizeAddress($request, $address);

        $wasDefault = $address->is_default;
        $user = $address->user;

        DB::transaction(function () use ($address, $wasDefault, $user) {
            $address->delete();

            if ($wasDefault) {
                $user->addresses()->orderByDesc('id')->first()?->update(['is_default' => true]);
            }
        });

        return response()->json(['ok' => true, 'message' => 'آدرس حذف شد.']);
    }

    public function defaultAddress(Request $request, Address $address): JsonResponse
    {
        $this->authorizeAddress($request, $address);

        DB::transaction(function () use ($request, $address) {
            $request->user()->addresses()->update(['is_default' => false]);
            $address->update(['is_default' => true]);
        });

        return response()->json(['ok' => true, 'message' => 'آدرس پیش‌فرض تعیین شد.']);
    }

    public function order(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        return response()->json([
            'ok' => true,
            'order' => $this->orderPayload($order->load('items')),
        ]);
    }

    /**
     * لغو سفارش توسط خود مشتری.
     *
     * فقط سفارشی که هنوز پرداخت/آماده‌سازی نشده قابل لغو است. موجودی کالا
     * فقط برای سفارش «پرداخت در محل» برمی‌گردد، چون تنها همان مسیر در زمان
     * ثبت سفارش از انبار کم می‌کند.
     */
    public function cancelOrder(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        if ($order->status !== 'pending') {
            return response()->json([
                'ok' => false,
                'error' => 'این سفارش در وضعیتی نیست که بتوانید لغو کنید؛ با پشتیبانی تماس بگیرید.',
            ], 422);
        }

        DB::transaction(function () use ($order) {
            if ($order->payment_method === 'cod') {
                foreach ($order->items as $item) {
                    Product::query()->where('slug', $item->product_slug)
                        ->increment('stock', $item->quantity);
                }
            }

            $order->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        });

        return response()->json([
            'ok' => true,
            'message' => 'سفارش لغو شد.',
            'order' => $this->orderPayload($order->fresh()->load('items')),
        ]);
    }

    public function reviews(Request $request): JsonResponse
    {
        $reviews = Review::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get();

        $names = Product::query()
            ->whereIn('slug', $reviews->pluck('product_slug')->unique())
            ->pluck('name', 'slug');

        return response()->json([
            'ok' => true,
            'items' => $reviews->map(fn (Review $review) => [
                'id' => $review->id,
                'productSlug' => $review->product_slug,
                'productName' => $names[$review->product_slug] ?? $review->product_slug,
                'rating' => (int) $review->rating,
                'title' => $review->title,
                'body' => $review->body,
                'status' => $review->status,
                'createdAt' => $review->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    /** @return array<string, mixed> */
    private function validatedAddress(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'title' => [$required, 'string', 'max:40'],
            'receiver' => [$required, 'string', 'max:80'],
            'phone' => [$required, 'string', 'regex:/^09\d{9}$/'],
            'province' => [$required, 'string', 'max:60'],
            'city' => [$required, 'string', 'max:60'],
            'line' => [$required, 'string', 'max:300'],
            'postalCode' => ['sometimes', 'nullable', 'string', 'regex:/^\d{10}$/'],
            'isDefault' => ['sometimes', 'boolean'],
        ], [
            'title.required' => 'عنوان آدرس را وارد کنید.',
            'receiver.required' => 'نام گیرنده را وارد کنید.',
            'phone.required' => 'شمارهٔ تماس گیرنده را وارد کنید.',
            'phone.regex' => 'شمارهٔ تماس باید با ۰۹ شروع شود و ۱۱ رقم باشد.',
            'province.required' => 'استان را وارد کنید.',
            'city.required' => 'شهر را وارد کنید.',
            'line.required' => 'نشانی کامل را وارد کنید.',
            'postalCode.regex' => 'کد پستی باید ۱۰ رقم باشد.',
        ]);
    }

    private function authorizeAddress(Request $request, Address $address): void
    {
        abort_if($address->user_id !== $request->user()->id, 404);
    }

    private function authorizeOrder(Request $request, Order $order): void
    {
        abort_if($order->user_id !== $request->user()->id, 404);
    }

    /** @return array<string, mixed> */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'phone' => $user->phone,
            'firstName' => $user->first_name,
            'lastName' => $user->last_name,
            'fullName' => $user->fullName(),
            'email' => $user->email,
            'role' => $user->role,
            'loyaltyPoints' => (int) $user->loyalty_points,
            'loyaltyTier' => $user->loyalty_tier,
            'memberSince' => $user->created_at?->toIso8601String(),
            'permissions' => $user->isAdmin() ? $user->effectivePermissions() : [],
        ];
    }

    /** @return array<string, mixed> */
    private function addressPayload(Address $address): array
    {
        return [
            'id' => $address->id,
            'title' => $address->title,
            'receiver' => $address->receiver,
            'phone' => $address->phone,
            'province' => $address->province,
            'city' => $address->city,
            'line' => $address->line,
            'postalCode' => $address->postal_code,
            'isDefault' => (bool) $address->is_default,
        ];
    }

    /** @return array<string, mixed> */
    private function orderPayload(Order $order): array
    {
        return [
            'id' => $order->id,
            'orderNumber' => $order->order_number,
            'status' => $order->status,
            'paymentMethod' => $order->payment_method,
            'shippingMethod' => $order->shipping_method,
            'subtotal' => (int) $order->subtotal,
            'discount' => (int) $order->discount,
            'shipping' => (int) $order->shipping,
            'total' => (int) $order->total,
            'couponCode' => $order->coupon_code,
            'trackingCode' => $order->tracking_code,
            'note' => $order->note,
            'shippingAddress' => [
                'province' => $order->shipping_province,
                'city' => $order->shipping_city,
                'line' => $order->shipping_line,
                'postalCode' => $order->shipping_postal_code,
            ],
            'createdAt' => $order->created_at?->toIso8601String(),
            'paidAt' => $order->paid_at?->toIso8601String(),
            'shippedAt' => $order->shipped_at?->toIso8601String(),
            'deliveredAt' => $order->delivered_at?->toIso8601String(),
            'cancelledAt' => $order->cancelled_at?->toIso8601String(),
            'canCancel' => $order->status === 'pending',
            'items' => $order->items->map(fn ($item) => [
                'slug' => $item->product_slug,
                'name' => $item->product_name,
                'unitPrice' => (int) $item->unit_price,
                'quantity' => (int) $item->quantity,
                'lineTotal' => (int) $item->line_total,
            ])->all(),
        ];
    }

    /** فهرست کوتاه استان‌ها برای انتخابگر آدرس. */
    private const PROVINCES = [
        'آذربایجان شرقی', 'آذربایجان غربی', 'اردبیل', 'اصفهان', 'البرز', 'ایلام', 'بوشهر',
        'تهران', 'چهارمحال و بختیاری', 'خراسان جنوبی', 'خراسان رضوی', 'خراسان شمالی',
        'خوزستان', 'زنجان', 'سمنان', 'سیستان و بلوچستان', 'فارس', 'قزوین', 'قم', 'کردستان',
        'کرمان', 'کرمانشاه', 'کهگیلویه و بویراحمد', 'گلستان', 'گیلان', 'لرستان', 'مازندران',
        'مرکزی', 'هرمزگان', 'همدان', 'یزد',
    ];
}
