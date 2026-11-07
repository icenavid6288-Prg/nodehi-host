<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Order;

/**
 * چرخهٔ پرداخت آنلاین.
 *
 * این کلاس «سیاست» پرداخت است و `ZarinpalGateway` «مکانیزم» آن: پروتکل درگاه
 * آن‌جاست و این‌جا فقط تصمیم‌های سفارش نودهی.
 *
 * ── چرا authority روی سفارش ذخیره می‌شود؟ ──
 * بازگشت از درگاه فقط `Authority` را با خود دارد، نه شماره سفارش. ستون
 * `payment_authority` یکتاست، پس پاسخ بازگشتی هرگز به سفارش دیگری نمی‌چسبد.
 *
 * ── idempotency ──
 * سه لایه محافظت:
 *   • سفارش تسویه‌شده دیگر وریفای نمی‌شود (بدون تماس با شبکه).
 *   • به‌روزرسانی وضعیت با شرط `status = 'pending'` انجام می‌شود؛ دو بازگشت
 *     هم‌زمان فقط یک اثر دارند.
 *   • کد ۱۰۱ زرین‌پال («قبلاً تأیید شده») موفق است، نه خطا.
 */
class OnlinePaymentService
{
    /** وضعیت‌هایی که یعنی پول رسیده است؛ از این‌جا دوباره وریفای نمی‌کنیم. */
    private const SETTLED_STATUSES = ['paid', 'processing', 'shipped', 'delivered'];

    public function __construct(private readonly ZarinpalGateway $gateway) {}

    public function isSettled(string $status): bool
    {
        return in_array($status, self::SETTLED_STATUSES, true);
    }

    /**
     * آغاز پرداخت برای یک سفارش.
     *
     * اگر سفارش قبلاً authority گرفته باشد همان برگردانده می‌شود؛ ساخت
     * تراکنش تازه در ازای هر کلیک، در پنل زرین‌پال انبوهی تراکنش بی‌استفاده
     * می‌سازد و می‌تواند به خطای ‎-12 بخورد.
     *
     * @return array{ok: true, authority: string, redirect_url: string, sandbox: bool, reused: bool}
     *                                                                                               |array{ok: false, state: string, message: string, retryable: bool}
     */
    public function start(Order $order): array
    {
        if ($order->payment_method !== 'online') {
            return [
                'ok' => false,
                'state' => 'not_online',
                'message' => 'این سفارش با روش پرداخت آنلاین ثبت نشده است.',
                'retryable' => false,
            ];
        }

        if ($this->isSettled($order->status)) {
            return [
                'ok' => false,
                'state' => 'already_paid',
                'message' => 'پرداخت این سفارش قبلاً انجام شده است.',
                'retryable' => false,
            ];
        }

        if ($order->status === 'cancelled') {
            return [
                'ok' => false,
                'state' => 'cancelled',
                'message' => 'این سفارش لغو شده است و پرداخت آن ممکن نیست.',
                'retryable' => false,
            ];
        }

        $config = $this->gateway->config();

        if (! $config['ready'] || $config['merchant_id'] === null) {
            return [
                'ok' => false,
                'state' => 'gateway_unavailable',
                'message' => $config['reason'] ?? 'درگاه پرداخت آنلاین در حال حاضر در دسترس نیست.',
                'retryable' => false,
            ];
        }

        if ($order->payment_authority) {
            return [
                'ok' => true,
                'authority' => $order->payment_authority,
                'redirect_url' => $order->payment_redirect_url
                    ?: ZarinpalGateway::buildStartPayUrl($order->payment_authority, $config['startpay_base']),
                'sandbox' => $config['sandbox'],
                'reused' => true,
            ];
        }

        $amountRial = ZarinpalGateway::toRial($order->total);
        $requested = $this->gateway->requestPayment(
            $amountRial,
            "پرداخت سفارش {$order->order_number} — فروشگاه نودهی",
            $order->customer_phone,
            $order->customer_email,
            $order->order_number,
        );

        if (! $requested['ok']) {
            $this->audit($order, 'payment.request_failed', [
                'orderNumber' => $order->order_number,
                'code' => $requested['code'],
                'message' => $requested['message'],
            ]);

            return [
                'ok' => false,
                'state' => 'gateway_error',
                'message' => $requested['message'],
                // خطای شبکه با تلاش دوباره حل می‌شود؛ خطای خود درگاه نه لزوماً.
                'retryable' => $requested['code'] === null,
            ];
        }

        // برخورد یکتایی یعنی این authority به سفارش دیگری گره خورده است؛
        // ادامه دادن یعنی هدایت کاربر به درگاهی که تسویه‌اش به سفارش دیگری می‌چسبد.
        try {
            $order->forceFill([
                'payment_authority' => $requested['authority'],
                'payment_redirect_url' => $requested['redirect_url'],
            ])->save();
        } catch (\Throwable) {
            return [
                'ok' => false,
                'state' => 'gateway_error',
                'message' => 'ثبت شناسه تراکنش درگاه ممکن نشد. دوباره تلاش کنید.',
                'retryable' => true,
            ];
        }

        $this->audit($order, 'payment.initiated', [
            'orderNumber' => $order->order_number,
            'amountRial' => $amountRial,
            'sandbox' => $config['sandbox'],
            'authority' => $requested['authority'],
        ]);

        return [
            'ok' => true,
            'authority' => $requested['authority'],
            'redirect_url' => $requested['redirect_url'],
            'sandbox' => $config['sandbox'],
            'reused' => false,
        ];
    }

    /**
     * تسویه پس از بازگشت از درگاه.
     *
     * تصمیم نهایی هرگز به `Status` بازگشتی واگذار نمی‌شود؛ تنها منبع حقیقت
     * پاسخ متد verify است. اگر به Status تکیه می‌کردیم، یک بازگشت دست‌کاری‌شده
     * می‌توانست سفارش را paid کند.
     *
     * @return array{ok: true, state: 'paid'|'already_paid', order_number: string, total: int, ref_id: ?string, card_pan: ?string}
     *                                                                                                                             |array{ok: false, state: 'failed'|'unverifiable'|'unknown', order_number: ?string, code: ?int, message: string}
     */
    public function settle(string $authority, ?string $gatewayStatus = null): array
    {
        $authority = trim($authority);

        if ($authority === '') {
            return [
                'ok' => false,
                'state' => 'unknown',
                'order_number' => null,
                'code' => null,
                'message' => 'شناسه تراکنش در بازگشت از درگاه یافت نشد.',
            ];
        }

        $order = Order::query()->where('payment_authority', $authority)->first();

        if (! $order) {
            return [
                'ok' => false,
                'state' => 'unknown',
                'order_number' => null,
                'code' => null,
                'message' => 'سفارشی با این شناسه تراکنش ثبت نشده است.',
            ];
        }

        // مسیر سریع: سفارش تسویه‌شده نیازی به تماس دوباره با درگاه ندارد.
        if ($this->isSettled($order->status)) {
            return $this->alreadyPaid($order);
        }

        if ($order->status === 'cancelled') {
            return [
                'ok' => false,
                'state' => 'unknown',
                'order_number' => $order->order_number,
                'code' => null,
                'message' => 'این سفارش لغو شده است؛ اگر مبلغی از حساب شما کسر شده با پشتیبانی تماس بگیرید.',
            ];
        }

        $config = $this->gateway->config();

        if (! $config['verify_ready'] || $config['merchant_id'] === null) {
            return [
                'ok' => false,
                'state' => 'unverifiable',
                'order_number' => $order->order_number,
                'code' => null,
                'message' => $config['reason'] ?? 'تأیید پرداخت در حال حاضر ممکن نیست.',
            ];
        }

        $verified = $this->gateway->verifyPayment(ZarinpalGateway::toRial($order->total), $authority);

        if (! $verified['ok']) {
            // خطای «قطعی» درگاه یعنی این authority مرده است و باید آزاد شود
            // تا مشتری بتواند تراکنش تازه‌ای شروع کند. خطای ارتباطی (کد تهی)
            // قطعی نیست: ممکن است پول رسیده باشد و فقط پاسخ به ما نرسیده؛
            // پاک‌کردن authority در آن حالت یعنی گم‌کردن رد پول مشتری.
            $definitive = $verified['code'] !== null;

            if ($definitive) {
                Order::query()
                    ->whereKey($order->getKey())
                    ->where('status', 'pending')
                    ->update([
                        'payment_authority' => null,
                        'payment_redirect_url' => null,
                        'updated_at' => now(),
                    ]);
            }

            $this->audit($order, 'payment.failed', [
                'orderNumber' => $order->order_number,
                'authority' => $authority,
                'status' => $gatewayStatus,
                'code' => $verified['code'],
                'definitive' => $definitive,
                'message' => $verified['message'],
            ]);

            return [
                'ok' => false,
                'state' => $definitive ? 'failed' : 'unverifiable',
                'order_number' => $order->order_number,
                'code' => $verified['code'],
                'message' => $verified['message'],
            ];
        }

        $cardPan = ZarinpalGateway::maskCardPan($verified['card_pan']);

        // شرط وضعیت در خود کوئری، مسابقهٔ دو بازگشت هم‌زمان را خنثی می‌کند:
        // فقط یکی رکورد را از pending به paid می‌برد و بقیه count=0 می‌گیرند.
        $applied = Order::query()
            ->whereKey($order->getKey())
            ->where('status', 'pending')
            ->update([
                'status' => 'paid',
                'paid_at' => now(),
                'payment_ref_id' => $verified['ref_id'],
                'payment_card_pan' => $cardPan,
                'updated_at' => now(),
            ]);

        $this->audit($order, 'order.paid', [
            'orderNumber' => $order->order_number,
            'amountRial' => ZarinpalGateway::toRial($order->total),
            'refId' => $verified['ref_id'],
            'cardPan' => $cardPan,
            'sandbox' => $config['sandbox'],
            'alreadyVerified' => $verified['already_verified'],
        ]);

        if ($applied === 0) {
            // در همین فاصله درخواست دیگری تسویه را انجام داده است.
            return $this->alreadyPaid($order->fresh() ?? $order, $verified['ref_id'], $cardPan);
        }

        return [
            'ok' => true,
            'state' => 'paid',
            'order_number' => $order->order_number,
            'total' => $order->total,
            'ref_id' => $verified['ref_id'],
            'card_pan' => $cardPan,
        ];
    }

    /**
     * @return array{ok: true, state: 'already_paid', order_number: string, total: int, ref_id: ?string, card_pan: ?string}
     */
    private function alreadyPaid(Order $order, ?string $fallbackRef = null, ?string $fallbackPan = null): array
    {
        return [
            'ok' => true,
            'state' => 'already_paid',
            'order_number' => $order->order_number,
            'total' => $order->total,
            'ref_id' => $order->payment_ref_id ?? $fallbackRef,
            'card_pan' => $order->payment_card_pan ?? $fallbackPan,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(Order $order, string $action, array $metadata): void
    {
        AuditLog::create([
            'actor_id' => $order->user_id,
            'action' => $action,
            'entity' => 'Order',
            'entity_id' => (string) $order->getKey(),
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
        ]);
    }
}
