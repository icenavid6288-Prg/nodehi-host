<?php

namespace App\Http\Controllers\Api;

use App\Models\PaymentGateway;
use App\Models\Setting;
use App\Services\SmsSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * یکپارچه‌سازی سایت: ابزارهای سئو/آنالیتیکس و نمای کلی وضعیت اتصال سرویس‌ها.
 *
 * شناسه‌ها عمومی‌اند و در `/api/settings/public` به فرانت‌اند می‌رسند تا داخل
 * صفحات تزریق شوند. «کد سفارشی head» فقط با نقش مدیر قابل ثبت است و همان‌جا
 * هشدار داده می‌شود که HTML خام است.
 */
class AdminIntegrationController extends AdminController
{
    /** @var array<string, array{label: string, type: string, max: int, hint?: string}> */
    private const CATALOG = [
        'seo.ga4' => [
            'label' => 'شناسهٔ Google Analytics 4',
            'type' => 'text',
            'max' => 40,
            'hint' => 'مثل G-XXXXXXX',
        ],
        'seo.gtm' => [
            'label' => 'شناسهٔ Google Tag Manager',
            'type' => 'text',
            'max' => 40,
            'hint' => 'مثل GTM-XXXXXX',
        ],
        'seo.gsc' => [
            'label' => 'کد تأیید Search Console',
            'type' => 'text',
            'max' => 120,
            'hint' => 'فقط مقدار content متا‌تگ google-site-verification',
        ],
        'seo.customHead' => [
            'label' => 'کد سفارشی در head',
            'type' => 'textarea',
            'max' => 4000,
            'hint' => 'HTML خام؛ فقط کدهای مورد اعتماد (پیکسل تبلیغات و مانند آن).',
        ],
    ];

    public function index(SmsSender $sms): JsonResponse
    {
        $gateway = PaymentGateway::query()->where('key', 'online')->first();

        return response()->json([
            'ok' => true,
            'items' => array_map(static function (string $key, array $meta) {
                return [
                    'key' => $key,
                    'label' => $meta['label'],
                    'type' => $meta['type'],
                    'hint' => $meta['hint'] ?? null,
                    'value' => (string) Setting::read($key, ''),
                ];
            }, array_keys(self::CATALOG), self::CATALOG),
            'status' => [
                'sms' => [
                    'configured' => $sms->configured(),
                    'sender' => $sms->sender() ?: null,
                ],
                'zarinpal' => [
                    'active' => (bool) ($gateway?->is_active ?? false),
                    'sandbox' => (bool) ($gateway?->is_sandbox ?? true),
                    'merchant' => $gateway?->maskedMerchantId(),
                ],
                'site' => [
                    'url' => (string) config('app.url'),
                    'php' => PHP_VERSION,
                    'storeName' => (string) Setting::read('site.name', ''),
                ],
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(
            ['items' => ['required', 'array', 'min:1']],
            ['items.required' => 'هیچ مقداری ارسال نشد.'],
        );

        foreach ($data['items'] as $key => $value) {
            $meta = self::CATALOG[$key] ?? null;

            if (! $meta) {
                throw ValidationException::withMessages(['items' => "کلید ناشناخته: {$key}"]);
            }

            $value = trim((string) $value);

            if ($value !== '' && mb_strlen($value) > $meta['max']) {
                throw ValidationException::withMessages([
                    "items.{$key}" => "«{$meta['label']}» حداکثر {$meta['max']} کاراکتر است.",
                ]);
            }

            if ($key === 'seo.ga4' && $value !== '' && ! preg_match('/^G-[A-Z0-9]{4,20}$/', $value)) {
                throw ValidationException::withMessages(['items.seo.ga4' => 'شناسهٔ GA4 باید با G- شروع شود.']);
            }

            if ($key === 'seo.gtm' && $value !== '' && ! preg_match('/^GTM-[A-Z0-9]{4,20}$/', $value)) {
                throw ValidationException::withMessages(['items.seo.gtm' => 'شناسهٔ GTM باید با GTM- شروع شود.']);
            }
        }

        foreach ($data['items'] as $key => $value) {
            if (! isset(self::CATALOG[$key])) {
                continue;
            }

            Setting::put($key, 'integration', trim((string) $value), false);
        }

        $this->audit($request, 'integrations.updated', 'setting', null, ['keys' => array_keys($data['items'])]);

        return response()->json(['ok' => true, 'message' => 'تنظیمات یکپارچه‌سازی ذخیره شد.']);
    }
}
