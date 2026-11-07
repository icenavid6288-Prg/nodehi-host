<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * کاتالوگ فروشگاه.
 *
 * ارجاع دسته و برند با «نامک» انجام می‌شود (هم‌راستا با سفارش‌ها و نظرها) و
 * فیلدهای چندمقداری (نکته‌ها، مشخصات، تصاویر، تگ‌ها) به شکل JSON ذخیره
 * می‌شوند تا انتقال به PostgreSQL فقط تغییر اتصال باشد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('name_en');
            // MySQL روی ستون‌های TEXT مقدار پیش‌فرض نمی‌پذیرد؛ پیش‌فرض
            // رشتهٔ خالی در مدل (App\Models\Category) گذاشته شده است.
            $table->text('description');
            $table->text('seo_intro');
            $table->string('icon')->default('refrigerator');
            $table->boolean('featured')->default(true);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('name_en')->default('');
            $table->string('origin')->default('');
            $table->text('description'); // پیش‌فرض در App\Models\Brand
            $table->boolean('featured')->default(false);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('category_slug');
            $table->string('brand_slug');
            $table->unsignedInteger('price'); // تومان — صفر یعنی «تماس بگیرید»
            $table->unsignedInteger('compare_at_price')->nullable();
            $table->integer('stock')->default(0);
            $table->float('rating')->default(0);
            $table->unsignedInteger('review_count')->default(0);
            $table->text('short_description');
            // ستون‌های TEXT/JSON در MySQL پیش‌فرض نمی‌پذیرند؛ مقادیر
            // پیش‌فرض در App\Models\Product تعریف شده‌اند.
            $table->longText('description');
            $table->json('highlights');
            $table->json('specs');
            $table->json('attributes');
            $table->json('images');
            $table->json('tags');
            $table->json('related_slugs');
            $table->string('video_url')->nullable();
            $table->timestamp('published_at')->useCurrent();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('category_slug');
            $table->index('brand_slug');
            $table->index('is_active');
            $table->index('price');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('categories');
    }
};
