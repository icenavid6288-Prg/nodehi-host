<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ارسال پیامک با کاوه‌نگار.
 *
 * کلید و شمارهٔ فرستنده اول از تنظیمات پنل (گروه `sms`) خوانده می‌شوند و اگر
 * خالی بودند، از `.env` می‌آیند. این ترتیب اجازه می‌دهد کلید را بدون دست‌زدن
 * به فایل‌های سرور از پنل مدیریت عوض کرد.
 */
class SmsSender
{
    public function apiKey(): string
    {
        return trim((string) (Setting::read('sms.apiKey') ?: config('services.kavenegar.api_key', '')));
    }

    public function sender(): string
    {
        return trim((string) (Setting::read('sms.sender') ?: config('services.kavenegar.sender', '')));
    }

    public function otpTemplate(): string
    {
        return trim((string) (Setting::read('sms.otpTemplate') ?: config('services.kavenegar.otp_template', '')));
    }

    public function configured(): bool
    {
        return $this->apiKey() !== '' && $this->otpTemplate() !== '';
    }

    /**
     * ارسال پیامک ساده (برای آزمون اتصال از پنل).
     *
     * @return array{ok: bool, error: ?string}
     */
    public function sendMessage(string $phone, string $message): array
    {
        $apiKey = $this->apiKey();

        if ($apiKey === '') {
            return ['ok' => false, 'error' => 'کلید کاوه‌نگار ثبت نشده است.'];
        }

        try {
            $response = Http::timeout(10)->acceptJson()->get(
                "https://api.kavenegar.com/v1/{$apiKey}/sms/send.json",
                array_filter([
                    'receptor' => $phone,
                    'message' => $message,
                    'sender' => $this->sender(),
                ], static fn ($value) => $value !== '' && $value !== null),
            );
        } catch (\Throwable $error) {
            Log::warning('Kavenegar send failed', ['message' => $error->getMessage()]);

            return ['ok' => false, 'error' => 'ارتباط با کاوه‌نگار برقرار نشد: '.$error->getMessage()];
        }

        $body = $response->json();
        $status = (int) ($body['return']['status'] ?? $response->status());

        if ($response->failed() || $status !== 200) {
            $message = (string) ($body['return']['message'] ?? 'پاسخ نامعتبر از کاوه‌نگار');

            Log::warning('Kavenegar send rejected', ['status' => $status, 'message' => $message]);

            return ['ok' => false, 'error' => "کاوه‌نگار درخواست را نپذیرفت ({$status}): {$message}"];
        }

        return ['ok' => true, 'error' => null];
    }
}
