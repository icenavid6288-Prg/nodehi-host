<?php

namespace Database\Seeders;

use App\Models\HeroSlide;
use Illuminate\Database\Seeder;

/**
 * سه اسلاید پیش‌فرض هیرو — همان متن‌ها و تصاویری که فرانت‌اند به‌عنوان
 * محتوای پشتیبان در خود دارد.
 *
 * اگر جدول خالی نباشد کاری نمی‌کند؛ پس اجرای دوبارهٔ seed متن‌های ویرایش‌شده
 * در پنل را بازنویسی نمی‌کند.
 */
class HeroSlideSeeder extends Seeder
{
    public function run(): void
    {
        if (HeroSlide::query()->exists()) {
            return;
        }

        $slides = [
            [
                'badge' => '۲۳ کالای منتخب با گارانتی رسمی',
                'title' => 'لوازم خانگی مطمئن،',
                'title_accent' => 'با ارسال سریع و نصب رایگان',
                'text' => 'یخچال، لباسشویی، ظرفشویی، تلویزیون و کولر گازی از برندهای معتبر؛ با ضمانت اصالت کالا و امکان پرداخت در محل.',
                'primary_label' => 'مشاهده فروشگاه',
                'primary_url' => '/shop',
                'secondary_label' => 'تخفیف‌های ویژه',
                'secondary_url' => '/shop?onSale=1',
                'features' => [
                    'ارسال همان‌روز در تهران و کرج',
                    'گارانتی معتبر و ضمانت اصالت',
                    'پرداخت در محل و خرید اقساطی',
                ],
                'image' => '/hero/promise.jpg',
                'theme' => 'primary',
                'sort_order' => 1,
                'is_active' => true,
            ],
            [
                'badge' => 'جشنواره تا ۳۵٪ تخفیف',
                'title' => 'تخفیف پایان فصل،',
                'title_accent' => 'روی منتخبی از لوازم خانگی',
                'text' => 'یخچال، لباسشویی و تلویزیون‌های پرفروش با تخفیف مستقیم، گارانتی رسمی و ارسال سریع — تا پایان موجودی.',
                'primary_label' => 'دیدن تخفیف‌ها',
                'primary_url' => '/shop?onSale=1',
                'secondary_label' => 'پرفروش‌ها',
                'secondary_url' => '/shop?sort=popular',
                'features' => [
                    'تخفیف تا ۳۵٪ روی کالاهای منتخب',
                    'ارسال رایگان برای خرید بالای ۱۰ میلیون تومان',
                    'امکان پرداخت در محل',
                ],
                'image' => '/hero/festival.jpg',
                'theme' => 'secondary',
                'sort_order' => 2,
                'is_active' => true,
            ],
            [
                'badge' => 'خرید اقساطی تا ۱۲ ماه',
                'title' => 'خرید اقساطی بدون دغدغه،',
                'title_accent' => 'با امتیاز باشگاه مشتریان',
                'text' => 'با هر خرید امتیاز بگیرید و در خرید بعدی تخفیف بگیرید؛ برای کالاهای منتخب، اقساط بدون بهره با بازپرداخت منعطف.',
                'primary_label' => 'شرایط خرید اقساطی',
                'primary_url' => '/loyalty',
                'secondary_label' => 'پیگیری سفارش',
                'secondary_url' => '/track',
                'features' => [
                    'اقساط ۴ تا ۱۲ ماهه',
                    'امتیاز باشگاه مشتریان روی هر خرید',
                    'پشتیبانی ۷ روز هفته',
                ],
                'image' => '/hero/installment.jpg',
                'theme' => 'accent',
                'sort_order' => 3,
                'is_active' => true,
            ],
        ];

        foreach ($slides as $slide) {
            HeroSlide::create($slide);
        }
    }
}
