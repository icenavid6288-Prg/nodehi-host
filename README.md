# API نودهی

بک‌اند فروشگاه با **Laravel 12** و Eloquent. دیتابیس پیش‌فرض SQLite است.

## نصب

```bash
cp .env.example .env
php ../tools/composer.phar install   # یا composer install
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan serve --host=127.0.0.1 --port=8000
```

## تست

```bash
php artisan test
```

## ساختار

- `app/Http/Controllers/Api/` — کنترلرهای فروشگاه
- `app/Http/Middleware/` — احراز هویت اجباری (`auth.token`) و اختیاری (`auth.optional`)
- `app/Models/` — مدل‌های Eloquent
- `app/Services/OtpSender.php` — ارسال کد ورود با کاوه‌نگار
- `database/migrations/` — ساختار جداول
- `database/seeders/data/` — کاتالوگ و محتوای اولیه
- `routes/api.php` — قرارداد API

## نکات

- قیمت، تخفیف و موجودی فقط سمت سرور محاسبه می‌شوند.
- در تولید `APP_DEBUG=false` و `FRONTEND_URL` را تنظیم کنید.
- برای ارسال واقعی کد ورود، `KAVENEGAR_API_KEY` و `KAVENEGAR_OTP_TEMPLATE` لازم است.
- محتوای مقالات، پرسش‌ها، صفحات حقوقی و باشگاه مشتریان در `database/seeders/data/content.json` نگه‌داری می‌شود.
