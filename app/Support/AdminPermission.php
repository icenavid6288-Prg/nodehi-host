<?php

namespace App\Support;

/**
 * فهرست مجوزهای پنل مدیریت.
 *
 * نقش `admin` همیشه همهٔ مجوزها را دارد تا امکان قفل‌شدن کامل پنل پیش نیاید.
 * برای نقش `operator` مجوزها دانه‌به‌دانه در جدول `user_permissions` ثبت
 * می‌شوند؛ اگر هیچ ردیفی ثبت نشده باشد، فهرست پیش‌فرض قدیمی (سفارش و کاتالوگ)
 * اعمال می‌شود تا کاربران فعلی دسترسی‌شان را از دست ندهند.
 */
final class AdminPermission
{
    public const PRODUCTS = 'products';

    public const ORDERS = 'orders';

    public const COUPONS = 'coupons';

    public const REVIEWS = 'reviews';

    public const HERO_SLIDES = 'hero_slides';

    public const USERS = 'users';

    public const SETTINGS = 'settings';

    public const INTEGRATIONS = 'integrations';

    public const GATEWAYS = 'gateways';

    public const SMS = 'sms';

    /** @var string[] */
    public const ALL = [
        self::PRODUCTS,
        self::ORDERS,
        self::COUPONS,
        self::REVIEWS,
        self::HERO_SLIDES,
        self::USERS,
        self::SETTINGS,
        self::INTEGRATIONS,
        self::GATEWAYS,
        self::SMS,
    ];

    /** دسترسی‌ای که اپراتورهای قبلی implicitly داشتند. */
    public const LEGACY_OPERATOR_DEFAULTS = [
        self::PRODUCTS,
        self::ORDERS,
        self::COUPONS,
        self::REVIEWS,
        self::HERO_SLIDES,
    ];

    /** @var array<string, string> */
    public const LABELS = [
        self::PRODUCTS => 'محصولات',
        self::ORDERS => 'سفارش‌ها',
        self::COUPONS => 'کدهای تخفیف',
        self::REVIEWS => 'نظرات',
        self::HERO_SLIDES => 'اسلایدهای هیرو',
        self::USERS => 'کاربران',
        self::SETTINGS => 'تنظیمات سایت',
        self::INTEGRATIONS => 'یکپارچه‌سازی و سئو',
        self::GATEWAYS => 'درگاه‌های پرداخت',
        self::SMS => 'سرویس پیامک',
    ];

    /** @return array<int, array{key: string, label: string}> */
    public static function catalog(): array
    {
        return array_map(
            static fn (string $key) => ['key' => $key, 'label' => self::LABELS[$key]],
            self::ALL,
        );
    }
}
