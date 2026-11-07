<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ارسال کد یک‌بارمصرف با کاوه‌نگار.
 *
 * اگر کلید و قالب تنظیم نشده باشند، false برمی‌گردد تا کنترلر به‌جای
 * ادعای «کد ارسال شد» خطای روشن بدهد. در محیط توسعه، حالت
 * AUTH_OTP_DEV_MODE کد را در پاسخ API نشان می‌دهد.
 */
class OtpSender
{
    public function __construct(private readonly SmsSender $sms) {}

    public function send(string $phone, string $code): bool
    {
        $apiKey = $this->sms->apiKey();
        $template = $this->sms->otpTemplate();

        if ($apiKey === '' || $template === '') {
            return false;
        }

        try {
            $response = Http::timeout(8)->acceptJson()->get(
                "https://api.kavenegar.com/v1/{$apiKey}/verify/lookup.json",
                [
                    'receptor' => $phone,
                    'token' => $code,
                    'template' => $template,
                ]
            );
        } catch (\Throwable $error) {
            Log::warning('Kavenegar OTP request failed', ['phone' => $phone, 'message' => $error->getMessage()]);

            return false;
        }

        if ($response->failed()) {
            Log::warning('Kavenegar OTP rejected', [
                'phone' => $phone,
                'status' => $response->status(),
            ]);

            return false;
        }

        return true;
    }
}
