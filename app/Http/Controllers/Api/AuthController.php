<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuthSession;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\OtpSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * ورود با شماره همراه و کد یک‌بارمصرف.
 *
 * تصمیم‌های امنیتی:
 *  ۱. کد خام ذخیره نمی‌شود؛ هش SHA-256 آن با شمارهٔ همراه به‌عنوان نمک
 *     نگه داشته می‌شود.
 *  ۲. با هر درخواست تازه، کدهای فعال قبلی همان شماره باطل می‌شوند.
 *  ۳. سقف درخواست هر شماره و هر IP جداگانه اعمال می‌شود.
 *  ۴. بدون سرویس پیامکِ تنظیم‌شده، در محیط تولید پاسخ ۵۰۳ داده می‌شود؛
 *     هرگز پیام «ارسال شد» بدون ارسال واقعی نمایش داده نمی‌شود.
 */
class AuthController extends Controller
{
    private const TTL_SECONDS = 120;
    private const MAX_ATTEMPTS = 5;
    private const MAX_REQUESTS_PER_PHONE = 3;

    public function requestOtp(Request $request, OtpSender $sender): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:20']]);
        $phone = $this->normalizePhone($data['phone']);

        if (! preg_match('/^09\d{9}$/', $phone)) {
            return response()->json(['ok' => false, 'error' => 'شماره همراه معتبر نیست.'], 422);
        }

        // سقف درخواست هر شماره: جلوگیری از بمباران پیامکی یک قربانی
        $throttleKey = 'otp:request:' . $phone;
        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_REQUESTS_PER_PHONE)) {
            return response()->json([
                'ok' => false,
                'error' => 'تعداد درخواست کد برای این شماره زیاد است؛ کمی بعد دوباره تلاش کنید.',
            ], 429)->header('Retry-After', (string) RateLimiter::availableIn($throttleKey));
        }
        RateLimiter::hit($throttleKey, 120);

        // کد فعال قبلی همان شماره باطل می‌شود تا فقط آخرین پیامک معتبر باشد
        OtpCode::query()
            ->where('phone', $phone)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = (string) random_int(10000, 99999);

        OtpCode::create([
            'phone' => $phone,
            'code_hash' => $this->hashCode($phone, $code),
            'purpose' => 'login',
            'attempts' => 0,
            'expires_at' => now()->addSeconds(self::TTL_SECONDS),
        ]);

        $devMode = $this->devMode();
        $sent = $sender->send($phone, $code);

        if (! $sent && ! $devMode) {
            return response()->json([
                'ok' => false,
                'error' => 'سرویس پیامک در دسترس نیست؛ لطفاً بعداً تلاش کنید یا با پشتیبانی تماس بگیرید.',
            ], 503);
        }

        $payload = [
            'ok' => true,
            'message' => $sent ? 'کد ورود ارسال شد.' : 'حالت توسعه فعال است؛ کد ورود در پاسخ آمده است.',
            'expiresIn' => self::TTL_SECONDS,
            'smsDelivered' => $sent,
        ];

        if ($devMode) {
            $payload['devCode'] = $code;
        }

        return response()->json($payload);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'code' => ['required', 'string', 'max:10'],
            'firstName' => ['nullable', 'string', 'max:60'],
            'lastName' => ['nullable', 'string', 'max:60'],
        ]);

        $phone = $this->normalizePhone($data['phone']);
        // فقط کد فعال و آخرین کد صادرشده؛ ترتیب با id است چون دو کد
        // می‌توانند در یک ثانیه ساخته شوند و created_at تمایز نمی‌دهد.
        $otp = OtpCode::query()
            ->where('phone', $phone)
            ->where('purpose', 'login')
            ->whereNull('consumed_at')
            ->orderByDesc('id')
            ->first();

        if (! $otp || ! $otp->isUsable()) {
            return response()->json(['ok' => false, 'error' => 'کد منقضی شده است؛ دوباره درخواست کنید.'], 422);
        }

        $submitted = $this->normalizeDigits($data['code']);

        if (! hash_equals($otp->code_hash, $this->hashCode($phone, $submitted))) {
            $otp->increment('attempts');

            $left = max(self::MAX_ATTEMPTS - $otp->attempts, 0);

            return response()->json([
                'ok' => false,
                'error' => $left > 0 ? "کد نادرست است؛ {$left} تلاش باقی مانده." : 'تعداد تلاش بیش از حد مجاز شد.',
            ], 422);
        }

        $otp->update(['consumed_at' => now()]);

        $user = User::firstOrCreate(
            ['phone' => $phone],
            [
                'first_name' => $data['firstName'] ?? null,
                'last_name' => $data['lastName'] ?? null,
                'role' => 'customer',
                'is_active' => true,
            ]
        );

        if (! $user->is_active) {
            return response()->json(['ok' => false, 'error' => 'این حساب غیرفعال شده است.'], 403);
        }

        $user->update([
            'phone_verified_at' => $user->phone_verified_at ?? now(),
            'last_login_at' => now(),
        ]);

        $token = bin2hex(random_bytes(32));
        $days = max((int) config('auth.session_days', 30), 1);

        AuthSession::create([
            'token_hash' => hash('sha256', $token),
            'user_id' => $user->id,
            'expires_at' => now()->addDays($days),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'ok' => true,
            'token' => $token,
            'user' => $this->userPayload($user),
        ]);
    }

    public function session(Request $request): JsonResponse
    {
        return response()->json(['ok' => true, 'user' => $this->userPayload($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $session = $request->attributes->get('auth_session');
        $session?->delete();

        return response()->json(['ok' => true, 'message' => 'از حساب خارج شدید.']);
    }

    /**
     * فقط پرچم مستقل AUTH_OTP_DEV_MODE، نه APP_DEBUG.
     *
     * جداکردنش از debug این امکان را می‌دهد که سایت با APP_DEBUG=false بالا
     * بیاید ولی تا پیش از ثبت کلید پیامک، مدیر بتواند وارد شود (کد در پاسخ
     * API و صفحهٔ ورود دیده می‌شود). با ثبت کلید پیامک، پرچم را صفر کنید.
     */
    private function devMode(): bool
    {
        return (bool) config('services.otp.dev_mode', false);
    }

    /** هش کد با نمک شمارهٔ همراه — هش دو کاربر با کد یکسان یکسان نیست. */
    private function hashCode(string $phone, string $code): string
    {
        return hash('sha256', $phone . '|' . $code);
    }

    private function userPayload(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'phone' => $user->phone,
            'firstName' => $user->first_name,
            'lastName' => $user->last_name,
            'fullName' => $user->fullName(),
            'role' => $user->role,
            'loyaltyPoints' => $user->loyalty_points,
            'loyaltyTier' => $user->loyalty_tier,
            // فقط برای اپراتور/مدیر؛ فرانت‌اند منوی پنل را با همین فیلتر می‌کند.
            'permissions' => $user->isAdmin() ? $user->effectivePermissions() : [],
        ];
    }

    private function normalizePhone(string $phone): string
    {
        $digits = $this->normalizeDigits($phone);
        $digits = preg_replace('/\D+/', '', $digits);

        if (str_starts_with($digits, '0098')) {
            $digits = '0' . substr($digits, 4);
        } elseif (str_starts_with($digits, '98') && strlen($digits) === 12) {
            $digits = '0' . substr($digits, 2);
        } elseif (str_starts_with($digits, '9') && strlen($digits) === 10) {
            $digits = '0' . $digits;
        }

        return $digits;
    }

    private function normalizeDigits(string $value): string
    {
        return strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }
}
