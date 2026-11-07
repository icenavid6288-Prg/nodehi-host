<?php

namespace App\Http\Controllers\Api;

use App\Models\Setting;
use App\Services\SmsSender;
use App\Support\Secret;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * اتصال سرویس پیامک (کاوه‌نگار) از پنل.
 *
 * کلید API رمزنگاری‌شده ذخیره می‌شود. اولویت خواندن: تنظیمات پنل، سپس
 * `.env`؛ پس اگر کلید از قبل در فایل محیطی هست، پنل آن را نشان می‌دهد ولی
 * نیازی به دوباره‌نویسی ندارد.
 */
class AdminSmsController extends AdminController
{
    public function show(SmsSender $sms): JsonResponse
    {
        $apiKey = $sms->apiKey();

        return response()->json([
            'ok' => true,
            'settings' => [
                'apiKey' => Secret::mask($apiKey),
                'hasApiKey' => $apiKey !== '',
                'source' => Setting::read('sms.apiKey') ? 'panel' : ($apiKey !== '' ? 'env' : 'none'),
                'sender' => (string) (Setting::read('sms.sender') ?: config('services.kavenegar.sender', '')),
                'otpTemplate' => (string) (Setting::read('sms.otpTemplate') ?: config('services.kavenegar.otp_template', '')),
            ],
            'configured' => $sms->configured(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'apiKey' => ['sometimes', 'nullable', 'string', 'max:300'],
            'sender' => ['sometimes', 'nullable', 'string', 'max:40'],
            'otpTemplate' => ['sometimes', 'nullable', 'string', 'max:60'],
        ], [
            'apiKey.max' => 'کلید API بیش از حد بلند است.',
        ]);

        if (array_key_exists('apiKey', $data) && ! Secret::isMask($data['apiKey'])) {
            Setting::put('sms.apiKey', 'sms', $data['apiKey'], true);
        }

        foreach (['sender' => 'sms.sender', 'otpTemplate' => 'sms.otpTemplate'] as $key => $settingKey) {
            if (array_key_exists($key, $data)) {
                Setting::put($settingKey, 'sms', $data[$key], false);
            }
        }

        $this->audit($request, 'sms.updated', 'setting', null, ['fields' => array_keys($data)]);

        return response()->json(['ok' => true, 'message' => 'تنظیمات پیامک ذخیره شد.']);
    }

    public function test(Request $request, SmsSender $sms): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:/^09\\d{9}$/'],
            'message' => ['sometimes', 'nullable', 'string', 'max:200'],
        ], [
            'phone.required' => 'شمارهٔ همراه را وارد کنید.',
            'phone.regex' => 'شماره باید با ۰۹ شروع شود و ۱۱ رقم باشد.',
        ]);

        $result = $sms->sendMessage($data['phone'], $data['message'] ?? 'پیام آزمایشی نودهی');

        $this->audit($request, 'sms.tested', 'setting', null, ['ok' => $result['ok']]);

        if (! $result['ok']) {
            return response()->json(['ok' => false, 'error' => $result['error']], 422);
        }

        return response()->json(['ok' => true, 'message' => 'پیام آزمایشی ارسال شد.']);
    }
}
