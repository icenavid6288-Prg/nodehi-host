<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OnlinePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * پرداخت آنلاین با زرین‌پال.
 *
 * دو مسیر دارد:
 *  • `start` — شروع (یا ادامهٔ) پرداخت یک سفارش؛ خروجی لینک درگاه است.
 *  • `callback` — بازگشت مرورگر از درگاه؛ همیشه verify می‌شود و سپس کاربر
 *    به صفحهٔ نتیجه در SPA ریدایرکت می‌شود.
 */
class PaymentController extends Controller
{
    public function __construct(private readonly OnlinePaymentService $payments) {}

    /**
     * POST /api/payments/zarinpal/start
     *
     * مالکیت سفارش با حساب واردشده یا شمارهٔ همراه همان سفارش بررسی
     * می‌شود؛ پیام «یافت نشد» عمداً سفارش دیگران را لو نمی‌دهد.
     */
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'orderNumber' => ['required', 'string', 'max:40'],
            'phone' => ['nullable', 'string', 'max:20'],
        ]);

        $order = Order::query()->where('order_number', $data['orderNumber'])->first();

        if (! $order) {
            return response()->json(['ok' => false, 'error' => 'سفارشی با این مشخصات یافت نشد.'], 404);
        }

        $user = $request->user();
        $ownsOrder = $user !== null && (int) $order->user_id === (int) $user->id;
        $phoneMatches = isset($data['phone'])
            && hash_equals((string) $order->customer_phone, (string) $data['phone']);

        if (! $ownsOrder && ! $phoneMatches) {
            return response()->json(['ok' => false, 'error' => 'سفارشی با این مشخصات یافت نشد.'], 404);
        }

        $started = $this->payments->start($order);

        if (! $started['ok']) {
            $status = match ($started['state']) {
                'already_paid', 'cancelled' => 409,
                'gateway_unavailable' => 503,
                'gateway_error' => 502,
                'not_online' => 422,
                default => 422,
            };

            return response()->json([
                'ok' => false,
                'error' => $started['message'],
                'state' => $started['state'],
                'retryable' => $started['retryable'],
            ], $status);
        }

        return response()->json([
            'ok' => true,
            'gateway' => 'zarinpal',
            'authority' => $started['authority'],
            'redirectUrl' => $started['redirect_url'],
            'sandbox' => $started['sandbox'],
            'reused' => $started['reused'],
        ]);
    }

    /**
     * GET /api/payments/zarinpal/callback?Authority=...&Status=OK|NOK
     *
     * `Status` فقط برای گزارش ثبت می‌شود و تصمیم نهایی با verify است.
     * پاسخ برای مرورگر ۳۰۳ به صفحهٔ نتیجه است و با `Accept: application/json`
     * همان وضعیت را JSON برمی‌گرداند (برای آزمون و پایش).
     */
    public function callback(Request $request): RedirectResponse|JsonResponse
    {
        // زرین‌پال پارامترها را با حرف بزرگ اول می‌فرستد؛ هر دو حالت پذیرفته می‌شود.
        $authority = trim((string) ($request->query('Authority') ?? $request->query('authority') ?? ''));
        $gatewayStatus = $request->query('Status') ?? $request->query('status');

        $result = $this->payments->settle($authority, is_string($gatewayStatus) ? $gatewayStatus : null);

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => $result['ok'],
                'state' => $result['state'],
                'orderNumber' => $result['order_number'],
                'total' => $result['ok'] ? $result['total'] : null,
                'refId' => $result['ok'] ? $result['ref_id'] : null,
                'code' => $result['ok'] ? null : $result['code'],
                'message' => $result['ok'] ? null : $result['message'],
            ]);
        }

        $params = array_filter([
            'state' => $result['state'],
            'order' => $result['order_number'],
            'code' => $result['ok'] ? null : $result['code'],
        ], fn ($value) => $value !== null && $value !== '');

        $target = rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/')
            .'/checkout/result?'.http_build_query($params);

        return redirect()->away($target, 303);
    }
}
