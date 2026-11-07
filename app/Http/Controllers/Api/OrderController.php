<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    /** پیگیری سفارش با شماره سفارش و شماره تماس. */
    public function track(Request $request): JsonResponse
    {
        $data = $request->validate([
            'orderNumber' => ['required', 'string', 'max:40'],
            'phone' => ['required', 'string', 'max:20'],
        ]);

        $order = Order::with('items')
            ->where('order_number', $data['orderNumber'])
            ->where('customer_phone', $data['phone'])
            ->first();

        if (! $order) {
            return response()->json(['ok' => false, 'error' => 'سفارشی با این مشخصات یافت نشد.'], 404);
        }

        return response()->json(['ok' => true, 'order' => $this->payload($order)]);
    }

    public function myOrders(Request $request): JsonResponse
    {
        $orders = Order::with('items')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (Order $o) => $this->payload($o));

        return response()->json(['ok' => true, 'items' => $orders]);
    }

    public function payload(Order $order): array
    {
        return [
            'id' => $order->id,
            'orderNumber' => $order->order_number,
            'status' => $order->status,
            'paymentMethod' => $order->payment_method,
            'shippingMethod' => $order->shipping_method,
            'subtotal' => $order->subtotal,
            'discount' => $order->discount,
            'shipping' => $order->shipping,
            'total' => $order->total,
            'couponCode' => $order->coupon_code,
            'customerName' => $order->customerName(),
            'trackingCode' => $order->tracking_code,
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
            'items' => $order->items->map(fn ($item) => [
                'slug' => $item->product_slug,
                'name' => $item->product_name,
                'unitPrice' => $item->unit_price,
                'quantity' => $item->quantity,
                'lineTotal' => $item->line_total,
            ]),
        ];
    }
}
