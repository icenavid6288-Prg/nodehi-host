<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * محتوای صفحات عمومی: مقالات، سوالات متداول، صفحات حقوقی و باشگاه مشتریان.
 *
 * بخش‌های ساختاریافته (متن مقاله، کلیدواژه‌ها، مزایای سطح عضویت و سند حقوقی)
 * به شکل JSON ذخیره می‌شوند تا افزودن بلوک تازه به محتوا نیاز به مهاجرت
 * دوباره نداشته باشد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('label');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('excerpt');
            $table->json('body'); // آرایه پاراگراف‌ها به ترتیب نمایش
            $table->string('category_slug');
            $table->string('author')->default('');
            $table->date('published_at');
            $table->date('content_updated_at');
            $table->unsignedSmallInteger('reading_minutes')->default(4);
            $table->json('keywords'); // پیش‌فرض در App\Models\Article
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('category_slug');
            $table->index('is_active');
            $table->index('published_at');
        });

        Schema::create('faq_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('label');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->string('category_slug');
            $table->text('question');
            $table->text('answer');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('category_slug');
            $table->index('is_active');
        });

        Schema::create('legal_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('intro');
            // [{ id, title, paragraphs: string[], bullets?: string[] }]
            $table->json('sections');
            $table->date('content_updated_at');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::create('loyalty_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('requirement_label');
            $table->string('discount_label');
            $table->string('points_label');
            $table->json('perks'); // پیش‌فرض در App\Models\LoyaltyTier
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::create('loyalty_installments', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('months')->unique();
            $table->string('label');
            $table->string('interest_label');
            $table->string('note')->default('');
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('loyalty_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('step');
            $table->string('title');
            $table->text('description');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_steps');
        Schema::dropIfExists('loyalty_installments');
        Schema::dropIfExists('loyalty_tiers');
        Schema::dropIfExists('legal_pages');
        Schema::dropIfExists('faqs');
        Schema::dropIfExists('faq_categories');
        Schema::dropIfExists('articles');
        Schema::dropIfExists('article_categories');
    }
};
