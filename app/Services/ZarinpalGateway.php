<?php

namespace App\Services;

use App\Models\PaymentGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * درگاه پرداخت زرین‌پال.
 *
 * ── چرا سرویس جدا؟ ──
 * هیچ کنترلر یا سرویس سفارش نباید مستقیم با زرین‌پال حرف بزند. هر چیز مربوط
 * به پروتکل درگاه (endpoint، پارامتر، تفسیر کد) همین‌جاست و سیاست سفارش در
 * `OnlinePaymentService`؛ با این تفکیک، آزمون پروتکل به دیتابیس نیاز ندارد.
 *
 * ── اولویت پیکربندی ──
 * ردیف درگاه در دیتابیس → متغیر محیطی → پیش‌فرض. اگر مدیر درگاه را خاموش
 * کند، وجود شناسه پذیرنده هم آن را روشن نمی‌کند. تفاوت `ready` و
 * `verifyReady` عمدی است: خاموش‌شدن درگاه باید جلوی **شروع** تراکنش تازه را
 * بگیرد، ولی نباید تأیید تراکنشی را که مشتری پیش از خاموش‌شدن آغاز کرده
 * مسدود کند.
 *
 * ── واحد پول ──
 * قیمت‌های کاتالوگ به تومان است و مستندات زرین‌پال برای `request` و `verify`
 * مبلغ را ریال اعلام کرده است. پس مبلغ فقط از `toRial()` می‌گذرد و پارامتر
 * اختیاری `currency` (IRT) عمداً فرستاده نمی‌شود؛ اگر یکی از دو متد تومان و
 * دیگری ریال فرستاده شود، درگاه با خطای ‎-50 تراکنش را رد می‌کند.
 */
class ZarinpalGateway
{
    /** کلید درگاه در جدول `payment_gateways` — با روش پرداخت تسویه یکی است. */
    public const GATEWAY_KEY = 'online';

    public const ENDPOINTS = [
        'live' => [
            'api' => 'https://payment.zarinpal.com/pg/v4/payment',
            'startpay' => 'https://payment.zarinpal.com/pg/StartPay',
        ],
        'sandbox' => [
            'api' => 'https://sandbox.zarinpal.com/pg/v4/payment',
            'startpay' => 'https://sandbox.zarinpal.com/pg/StartPay',
        ],
    ];

    /** سقف مبلغ هر تراکنش درگاه (تومان) — خطای ‎-41 */
    public const MAX_AMOUNT_TOMAN = 100_000_000;

    /** متن فارسی خطاهای شناخته‌شدهٔ درگاه. */
    public const ERROR_MESSAGES = [
        '-9' => 'اطلاعات ارسالی به درگاه کامل یا معتبر نیست.',
        '-10' => 'آی‌پی یا شناسه پذیرندهٔ زرین‌پال درست نیست.',
        '-11' => 'شناسه پذیرنده در زرین‌پال فعال نیست.',
        '-12' => 'تلاش‌های بیش از حد مجاز در بازه زمانی کوتاه؛ کمی بعد دوباره تلاش کنید.',
        '-13' => 'محدودیت تراکنش پذیرنده؛ تکمیل مدارک در زرین‌پال لازم است.',
        '-14' => 'آدرس بازگشت با دامنهٔ ثبت‌شدهٔ درگاه در زرین‌پال مغایرت دارد.',
        '-15' => 'درگاه پرداخت به حالت تعلیق درآمده است.',
        '-16' => 'سطح تأیید پذیرنده برای این تراکنش کافی نیست.',
        '-17' => 'محدودیت پذیرنده در سطح آبی؛ امکان انجام این تراکنش نیست.',
        '-18' => 'استفاده از کد درگاه اختصاصی روی این دامنه مجاز نیست.',
        '-19' => 'امکان ایجاد تراکنش برای این پذیرنده وجود ندارد.',
        '-40' => 'پارامترهای ارسالی به درگاه نامعتبر است.',
        '-41' => 'حداکثر مبلغ مجاز هر تراکنش ۱۰۰ میلیون تومان است.',
        '-50' => 'مبلغ تأییدشده با مبلغ ثبت‌شدهٔ سفارش یکسان نیست.',
        '-51' => 'پرداخت ناموفق بود یا توسط خریدار لغو شد.',
        '-52' => 'خطای غیرمنتظره‌ای در درگاه رخ داد.',
        '-53' => 'این پرداخت به شناسه پذیرندهٔ دیگری تعلق دارد.',
        '-54' => 'شناسه تراکنش (authority) نامعتبر است.',
        '-55' => 'تراکنش مورد نظر در درگاه یافت نشد.',
    ];

    /** تبدیل تومان به ریال — تنها نقطهٔ تبدیل در پروژه */
    public static function toRial(int $toman): int
    {
        return $toman * 10;
    }

    /** فقط ۴ رقم آخر شماره کارت نگه داشته می‌شود. */
    public static function maskCardPan(?string $pan): ?string
    {
        if ($pan === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $pan);

        return $digits === null || strlen($digits) < 4 ? null : '****'.substr($digits, -4);
    }

    public static function errorMessage(?int $code, string $fallback = 'خطای نامشخص درگاه پرداخت'): string
    {
        if ($code === null) {
            return $fallback;
        }

        return self::ERROR_MESSAGES[(string) $code] ?? $fallback;
    }

    public static function buildStartPayUrl(string $authority, string $startPayBase): string
    {
        return rtrim($startPayBase, '/').'/'.rawurlencode($authority);
    }

    /**
     * پیکربندی مؤثر درگاه.
     *
     * @return array{
     *     ready: bool, verify_ready: bool, disabled: bool, reason: ?string,
     *     merchant_id: ?string, sandbox: bool, api_base: string,
     *     startpay_base: string, timeout: int
     * }
     */
    public function config(): array
    {
        $gateway = PaymentGateway::query()->where('key', self::GATEWAY_KEY)->first();

        $sandbox = $gateway ? (bool) $gateway->is_sandbox : (bool) config('services.zarinpal.sandbox', true);
        $endpoints = self::ENDPOINTS[$sandbox ? 'sandbox' : 'live'];

        $apiBase = trim((string) (config('services.zarinpal.api_base') ?: $endpoints['api']));
        $startPayBase = trim((string) (config('services.zarinpal.startpay_base') ?: $endpoints['startpay']));

        $merchantId = trim((string) ($gateway?->merchant_id ?: config('services.zarinpal.merchant_id') ?: ''));
        $disabled = $gateway !== null && ! $gateway->is_active;

        $base = [
            'ready' => false,
            'verify_ready' => false,
            'disabled' => $disabled,
            'reason' => null,
            'merchant_id' => $merchantId === '' ? null : $merchantId,
            'sandbox' => $sandbox,
            'api_base' => $apiBase,
            'startpay_base' => $startPayBase,
            'timeout' => max((int) config('services.zarinpal.timeout', 9), 1),
        ];

        if ($base['merchant_id'] === null) {
            return [...$base, 'reason' => 'شناسه پذیرندهٔ زرین‌پال در پنل مدیریت ثبت نشده است.'];
        }
        if ($disabled) {
            return [...$base, 'verify_ready' => true, 'reason' => 'پرداخت آنلاین در پنل مدیریت غیرفعال است.'];
        }

        return [...$base, 'ready' => true, 'verify_ready' => true];
    }

    /**
     * آدرس بازگشت از درگاه.
     * زرین‌پال آن را با دامنهٔ ثبت‌شدهٔ پذیرنده مقایسه می‌کند، پس در تولید
     * باید صریح تنظیم شود؛ وگرنه از APP_URL (آدرس خود API) ساخته می‌شود.
     */
    public function callbackUrl(): string
    {
        $explicit = trim((string) config('services.zarinpal.callback_url'));
        if ($explicit !== '') {
            return $explicit;
        }

        return rtrim((string) config('app.url', 'http://localhost'), '/').'/api/payments/zarinpal/callback';
    }

    /**
     * درخواست پرداخت و دریافت authority.
     *
     * @return array{ok: true, authority: string, redirect_url: string}
     *                                                                  |array{ok: false, code: ?int, message: string}
     */
    public function requestPayment(int $amountRial, string $description, ?string $mobile = null, ?string $email = null, ?string $orderNumber = null): array
    {
        $config = $this->config();

        if (! $config['ready'] || $config['merchant_id'] === null) {
            return ['ok' => false, 'code' => null, 'message' => $config['reason'] ?? 'درگاه پرداخت آنلاین آماده نیست.'];
        }
        if ($amountRial <= 0) {
            return ['ok' => false, 'code' => -9, 'message' => 'مبلغ پرداخت نامعتبر است.'];
        }
        if ($amountRial > self::toRial(self::MAX_AMOUNT_TOMAN)) {
            return ['ok' => false, 'code' => -41, 'message' => self::errorMessage(-41)];
        }

        $metadata = array_filter([
            'mobile' => $mobile,
            'email' => $email,
            'order_id' => $orderNumber,
        ], fn ($value) => $value !== null && $value !== '');

        $payload = [
            'merchant_id' => $config['merchant_id'],
            'amount' => $amountRial,
            'callback_url' => $this->callbackUrl(),
            'description' => $description,
        ];
        if ($metadata !== []) {
            $payload['metadata'] = $metadata;
        }

        $sent = $this->post($config['api_base'].'/request.json', $payload, $config['timeout']);
        if (! $sent['ok']) {
            return $sent;
        }

        $parsed = $this->parseRequest($sent['payload']);
        if (! $parsed['ok']) {
            return $parsed;
        }

        return [
            'ok' => true,
            'authority' => $parsed['authority'],
            'redirect_url' => self::buildStartPayUrl($parsed['authority'], $config['startpay_base']),
        ];
    }

    /**
     * تأیید پرداخت پس از بازگشت کاربر.
     *
     * @return array{ok: true, code: int, ref_id: ?string, card_pan: ?string, already_verified: bool}
     *                                                                                                |array{ok: false, code: ?int, message: string}
     */
    public function verifyPayment(int $amountRial, string $authority): array
    {
        $config = $this->config();

        // عمداً `verify_ready` و نه `ready`: تراکنشی که آغاز شده باید حتی پس
        // از خاموش‌شدن درگاه تأیید شود تا پول مشتری گم نشود.
        if (! $config['verify_ready'] || $config['merchant_id'] === null) {
            return ['ok' => false, 'code' => null, 'message' => $config['reason'] ?? 'تأیید پرداخت درگاه آنلاین ممکن نیست.'];
        }

        $sent = $this->post($config['api_base'].'/verify.json', [
            'merchant_id' => $config['merchant_id'],
            'amount' => $amountRial,
            'authority' => $authority,
        ], $config['timeout']);

        if (! $sent['ok']) {
            return $sent;
        }

        return $this->parseVerify($sent['payload']);
    }

    /**
     * تفسیر پاسخ «درخواست پرداخت»؛ تنها کد موفق ۱۰۰ است.
     *
     * @return array{ok: true, authority: string}|array{ok: false, code: ?int, message: string}
     */
    public function parseRequest(mixed $payload): array
    {
        $record = $this->asRecord($payload);
        if ($record === null) {
            return ['ok' => false, 'code' => null, 'message' => 'پاسخ درگاه قابل خواندن نبود.'];
        }

        $data = $this->asRecord($record['data'] ?? null);
        $code = is_array($data) && is_int($data['code'] ?? null) ? $data['code'] : null;

        if ($code === 100) {
            $authority = $this->asText($data['authority'] ?? null);

            return $authority === null
                ? ['ok' => false, 'code' => null, 'message' => 'درگاه در پاسخ خود شناسه تراکنش برنگرداند.']
                : ['ok' => true, 'authority' => $authority];
        }

        $error = $this->readError($payload);
        $finalCode = $error['code'] ?? $code;

        return [
            'ok' => false,
            'code' => $finalCode,
            'message' => self::errorMessage($finalCode, $error['message'] ?? 'درخواست پرداخت پذیرفته نشد.'),
        ];
    }

    /**
     * تفسیر پاسخ «تأیید پرداخت».
     * کد ۱۰۱ یعنی «قبلاً تأیید شده» و موفق است؛ اگر خطا گرفته شود، مشتری
     * پول داده ولی سفارش pending می‌ماند (مثلاً هنگام رفرش صفحه).
     *
     * @return array{ok: true, code: int, ref_id: ?string, card_pan: ?string, already_verified: bool}
     *                                                                                                |array{ok: false, code: ?int, message: string}
     */
    public function parseVerify(mixed $payload): array
    {
        $record = $this->asRecord($payload);
        if ($record === null) {
            return ['ok' => false, 'code' => null, 'message' => 'پاسخ درگاه قابل خواندن نبود.'];
        }

        $data = $this->asRecord($record['data'] ?? null);
        $code = is_array($data) && is_int($data['code'] ?? null) ? $data['code'] : null;

        if ($code === 100 || $code === 101) {
            return [
                'ok' => true,
                'code' => $code,
                'ref_id' => $this->asText($data['ref_id'] ?? null),
                'card_pan' => $this->asText($data['card_pan'] ?? null),
                'already_verified' => $code === 101,
            ];
        }

        $error = $this->readError($payload);
        $finalCode = $error['code'] ?? $code;

        return [
            'ok' => false,
            'code' => $finalCode,
            'message' => self::errorMessage($finalCode, $error['message'] ?? 'تأیید پرداخت از سوی درگاه انجام نشد.'),
        ];
    }

    /**
     * استخراج خطا؛ زرین‌پال آن را هم آرایه و هم شیء برمی‌گرداند.
     *
     * @return array{code: ?int, message: ?string}
     */
    public function readError(mixed $payload): array
    {
        $record = $this->asRecord($payload);
        if ($record === null) {
            return ['code' => null, 'message' => null];
        }

        $raw = $record['errors'] ?? null;
        $candidate = is_array($raw) && array_is_list($raw) ? ($raw[0] ?? null) : $raw;

        $errorRecord = $this->asRecord($candidate);
        if ($errorRecord !== null) {
            $code = is_int($errorRecord['code'] ?? null) ? $errorRecord['code'] : null;

            return ['code' => $code, 'message' => $this->asText($errorRecord['message'] ?? null)];
        }

        return ['code' => null, 'message' => $this->asText($candidate)];
    }

    /**
     * فراخوانی POST درگاه. بدنهٔ خطا هم خوانده می‌شود، چون زرین‌پال دلیل رد
     * شدن را همان‌جا می‌گذارد.
     *
     * @param  array<string, mixed>  $body
     * @return array{ok: true, payload: mixed}|array{ok: false, code: ?int, message: string}
     */
    private function post(string $url, array $body, int $timeout): array
    {
        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->asJson()
                ->post($url, $body);

            $payload = $response->json();

            if ($payload === null) {
                return ['ok' => false, 'code' => null, 'message' => 'پاسخ درگاه قابل خواندن نبود (HTTP '.$response->status().').'];
            }

            return ['ok' => true, 'payload' => $payload];
        } catch (ConnectionException|Throwable $error) {
            return [
                'ok' => false,
                'code' => null,
                'message' => 'ارتباط با درگاه پرداخت برقرار نشد ('.$error->getMessage().').',
            ];
        }
    }

    /** @return array<string, mixed>|null */
    private function asRecord(mixed $value): ?array
    {
        return is_array($value) && ! array_is_list($value) ? $value : null;
    }

    /** رشتهٔ خالی هم‌ارزش «مقدار ندارد» است. */
    private function asText(mixed $value): ?string
    {
        if (is_string($value)) {
            return trim($value) === '' ? null : trim($value);
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }
}
