<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'otp' => [
        // فقط برای راه‌اندازی/توسعه. با روشن‌بودن آن کد یک‌بارمصرف در پاسخ API
        // برمی‌گردد؛ در تولیدِ نهایی باید 0 باشد.
        'dev_mode' => filter_var(env('AUTH_OTP_DEV_MODE', false), FILTER_VALIDATE_BOOL),
    ],

    'zarinpal' => [
        // شناسه پذیرنده؛ اگر در پنل مدیریت برای درگاه online ثبت شده باشد،
        // همان مقدار بر این متغیر اولویت دارد.
        'merchant_id' => env('ZARINPAL_MERCHANT_ID'),
        'sandbox' => filter_var(env('ZARINPAL_SANDBOX', true), FILTER_VALIDATE_BOOL),
        // تزریق آدرس فقط برای پروکسی محلی و آزمون است.
        'api_base' => env('ZARINPAL_API_BASE'),
        'startpay_base' => env('ZARINPAL_STARTPAY_BASE'),
        // اگر تنظیم نشود، از FRONTEND_URL ساخته می‌شود؛ در تولید باید با
        // دامنهٔ ثبت‌شده در زرین‌پال یکی باشد (خطای ‎-14).
        'callback_url' => env('ZARINPAL_CALLBACK_URL'),
        'timeout' => (int) env('ZARINPAL_TIMEOUT', 9),
    ],

    'kavenegar' => [
        'api_key' => env('KAVENEGAR_API_KEY'),
        'sender' => env('KAVENEGAR_SENDER', '10008663'),
        'otp_template' => env('KAVENEGAR_OTP_TEMPLATE'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
