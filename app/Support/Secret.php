<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;

/**
 * رمزنگاری کلیدهای حساس (درگاه پرداخت و پیامک).
 *
 * مقدار رمزنگاری‌شده با نشانگر `enc:v1:` شروع می‌شود تا ردیف‌های قدیمی که
 * متن ساده دارند (یا مقادیر آمده از .env) بدون خطا خوانده شوند. در پنل فقط
 * چهار کاراکتر آخر نمایش داده می‌شود و ارسال دوبارهٔ همان ماسک، مقدار را
 * تغییر نمی‌دهد.
 */
final class Secret
{
    private const MARKER = 'enc:v1:';

    private const MASK_PREFIX = '••••';

    public static function seal(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, self::MARKER)) {
            return $value;
        }

        return self::MARKER.Crypt::encryptString($value);
    }

    public static function open(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! str_starts_with($value, self::MARKER)) {
            // ردیف قدیمی یا مقدار متنی .env
            return $value;
        }

        try {
            return Crypt::decryptString(substr($value, strlen(self::MARKER)));
        } catch (\Throwable) {
            return null;
        }
    }

    public static function mask(?string $plain): ?string
    {
        if ($plain === null || trim($plain) === '') {
            return null;
        }

        return self::MASK_PREFIX.mb_substr(trim($plain), -4);
    }

    /** آیا کاربر فیلد را دست‌نخورده (همان ماسک) برگردانده است؟ */
    public static function isMask(?string $value): bool
    {
        return is_string($value) && str_starts_with($value, self::MASK_PREFIX);
    }
}
