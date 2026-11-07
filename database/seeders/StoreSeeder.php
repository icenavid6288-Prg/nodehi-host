<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\PaymentGateway;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * داده اولیه فروشگاه — از فایل‌های JSON پوشهٔ seeders/data خوانده می‌شود تا
 * کاتالوگ فقط در یک جا نگه‌داری شود.
 */
class StoreSeeder extends Seeder
{
    public function run(): void
    {
        $dataDir = database_path('seeders/data');
        $categories = json_decode(file_get_contents($dataDir . '/categories.json'), true) ?: [];
        $brands = json_decode(file_get_contents($dataDir . '/brands.json'), true) ?: [];
        $products = json_decode(file_get_contents($dataDir . '/products.json'), true) ?: [];

        foreach ($categories as $i => $row) {
            Category::updateOrCreate(['slug' => $row['slug']], [
                'name' => $row['name'],
                'name_en' => $row['nameEn'] ?? $row['slug'],
                'description' => $row['description'] ?? '',
                'seo_intro' => $row['seoIntro'] ?? '',
                'icon' => $row['icon'] ?? 'refrigerator',
                'featured' => (bool) ($row['featured'] ?? true),
                'sort_order' => $i,
                'is_active' => true,
            ]);
        }

        foreach ($brands as $i => $row) {
            Brand::updateOrCreate(['slug' => $row['slug']], [
                'name' => $row['name'],
                'name_en' => $row['nameEn'] ?? '',
                'origin' => $row['origin'] ?? '',
                'description' => $row['description'] ?? '',
                'featured' => (bool) ($row['featured'] ?? false),
                'sort_order' => $i,
                'is_active' => true,
            ]);
        }

        foreach ($products as $row) {
            Product::updateOrCreate(['slug' => $row['slug']], [
                'name' => $row['name'],
                'category_slug' => $row['category'],
                'brand_slug' => $row['brand'],
                'price' => (int) $row['price'],
                'compare_at_price' => isset($row['compareAtPrice']) ? (int) $row['compareAtPrice'] : null,
                'stock' => (int) ($row['stock'] ?? 0),
                'rating' => (float) ($row['rating'] ?? 0),
                'review_count' => (int) ($row['reviewCount'] ?? 0),
                'short_description' => $row['shortDescription'] ?? '',
                'description' => $row['description'] ?? '',
                'highlights' => $row['highlights'] ?? [],
                'specs' => $row['specs'] ?? [],
                'attributes' => $row['attributes'] ?? [],
                'images' => $row['images'] ?? [],
                'tags' => $row['tags'] ?? [],
                'related_slugs' => $row['relatedSlugs'] ?? [],
                'video_url' => $row['videoUrl'] ?? null,
                'published_at' => $row['publishedAt'] ?? now(),
                'is_active' => true,
            ]);
        }

        $settings = [
            ['key' => 'site.name', 'group' => 'general', 'value' => 'نودهی'],
            ['key' => 'site.tagline', 'group' => 'general', 'value' => 'لوازم خانگی مطمئن، با گارانتی و ارسال سریع'],
            ['key' => 'contact.phone', 'group' => 'general', 'value' => '۰۲۱-۹۱۰۰۰۰۰۰'],
            ['key' => 'contact.email', 'group' => 'general', 'value' => 'info@nodehi.ir'],
            ['key' => 'contact.address', 'group' => 'general', 'value' => 'تهران، خیابان ولیعصر، پلاک ۱۲۳، طبقه سوم'],
            ['key' => 'shipping.freeThreshold', 'group' => 'general', 'value' => '20000000'],
            ['key' => 'shipping.cost', 'group' => 'general', 'value' => '450000'],
        ];
        foreach ($settings as $row) {
            Setting::updateOrCreate(['key' => $row['key']], $row + ['is_secret' => false]);
        }

        $gateways = [
            ['key' => 'cod', 'label' => 'پرداخت در محل', 'description' => 'پرداخت نقدی هنگام تحویل کالا', 'is_active' => true, 'is_sandbox' => false, 'sort_order' => 0],
            ['key' => 'online', 'label' => 'پرداخت آنلاین', 'description' => 'پرداخت امن با کارت بانکی', 'is_active' => true, 'is_sandbox' => true, 'sort_order' => 1],
            ['key' => 'installment', 'label' => 'خرید اقساطی', 'description' => 'پرداخت اقساطی با اسنپ‌پی', 'is_active' => true, 'is_sandbox' => true, 'sort_order' => 2],
        ];
        foreach ($gateways as $row) {
            PaymentGateway::updateOrCreate(['key' => $row['key']], $row);
        }

        Coupon::updateOrCreate(['code' => 'NODEHI10'], [
            'label' => '۱۰٪ تخفیف خوش‌آمدگویی',
            'percent' => 10,
            'min_subtotal' => 5000000,
            'max_uses' => 500,
            'is_active' => true,
        ]);

        User::updateOrCreate(['phone' => '09120000000'], [
            'first_name' => 'مدیر',
            'last_name' => 'نودهی',
            'role' => 'admin',
            'is_active' => true,
            'phone_verified_at' => now(),
        ]);
    }
}
