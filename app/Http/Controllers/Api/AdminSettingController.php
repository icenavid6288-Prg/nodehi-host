<?php

namespace App\Http\Controllers\Api;

use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * تنظیمات عمومی سایت از پنل مدیریت.
 *
 * فقط کلیدهای موجود در فهرست زیر قابل نوشتن هستند تا پنل نتواند تنظیمات
 * داخلی یا حساس را بازنویسی کند. تنظیمات حساس (پیامک و درگاه) جای دیگری
 * مدیریت می‌شوند.
 */
class AdminSettingController extends AdminController
{
    /** @var array<string, array{group: string, label: string, type: string, hint?: string, max?: int}> */
    private const CATALOG = [
        'site.name' => ['group' => 'general', 'label' => 'نام فروشگاه', 'type' => 'text', 'max' => 60],
        'site.tagline' => ['group' => 'general', 'label' => 'شعار / توضیح کوتاه', 'type' => 'text', 'max' => 160],
        'contact.phone' => ['group' => 'general', 'label' => 'تلفن پشتیبانی', 'type' => 'text', 'max' => 40],
        'contact.email' => ['group' => 'general', 'label' => 'ایمیل', 'type' => 'text', 'max' => 120],
        'contact.address' => ['group' => 'general', 'label' => 'نشانی', 'type' => 'textarea', 'max' => 300],
        'contact.instagram' => ['group' => 'general', 'label' => 'اینستاگرام', 'type' => 'text', 'max' => 120, 'hint' => 'آدرس کامل صفحه یا شناسه'],
        'contact.telegram' => ['group' => 'general', 'label' => 'تلگرام', 'type' => 'text', 'max' => 120],
        'shipping.cost' => ['group' => 'general', 'label' => 'هزینهٔ ارسال (تومان)', 'type' => 'number', 'max' => 12],
        'shipping.freeThreshold' => ['group' => 'general', 'label' => 'سقف ارسال رایگان (تومان)', 'type' => 'number', 'max' => 12, 'hint' => 'خرید بالاتر از این مبلغ ارسال رایگان دارد؛ صفر یعنی غیرفعال.'],
    ];

    public function index(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'groups' => [[
                'key' => 'general',
                'label' => 'اطلاعات فروشگاه و ارسال',
                'items' => array_map(function (string $key, array $meta) {
                    return [
                        'key' => $key,
                        'label' => $meta['label'],
                        'type' => $meta['type'],
                        'hint' => $meta['hint'] ?? null,
                        'value' => (string) Setting::read($key, ''),
                    ];
                }, array_keys(self::CATALOG), self::CATALOG),
            ]],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(
            ['settings' => ['required', 'array', 'min:1']],
            ['settings.required' => 'هیچ تنظیمی ارسال نشد.'],
        );

        // کلیدهای تنظیمات نقطه دارند (مثل site.name) و نمی‌توان برای هرکدام
        // قاعدهٔ نقطه‌دار نوشت؛ اعتبارسنجی هر مقدار دستی انجام می‌شود.
        foreach ($data['settings'] as $key => $value) {
            $meta = self::CATALOG[$key] ?? null;

            if (! $meta) {
                throw ValidationException::withMessages(['settings' => "کلید تنظیم ناشناخته: {$key}"]);
            }

            if ($value === null || $value === '') {
                continue;
            }

            if (! is_scalar($value)) {
                throw ValidationException::withMessages(["settings.{$key}" => "مقدار «{$meta['label']}» معتبر نیست."]);
            }

            if ($meta['type'] === 'number') {
                if (! preg_match('/^\d+$/', (string) $value)) {
                    throw ValidationException::withMessages(["settings.{$key}" => "«{$meta['label']}» باید عدد صحیح باشد."]);
                }
            } elseif (mb_strlen((string) $value) > ($meta['max'] ?? 255)) {
                throw ValidationException::withMessages([
                    "settings.{$key}" => "«{$meta['label']}» حداکثر {$meta['max']} کاراکتر می‌تواند باشد.",
                ]);
            }
        }

        $changed = [];

        foreach ($data['settings'] as $key => $value) {
            if (! isset(self::CATALOG[$key])) {
                continue;
            }

            Setting::put($key, self::CATALOG[$key]['group'], $value === null ? null : (string) $value, false);
            $changed[] = $key;
        }

        $this->audit($request, 'settings.updated', 'setting', null, ['keys' => $changed]);

        return response()->json(['ok' => true, 'message' => 'تنظیمات سایت ذخیره شد.', 'keys' => $changed]);
    }
}
