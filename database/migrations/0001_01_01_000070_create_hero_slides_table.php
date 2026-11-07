<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * اسلایدهای هیروی صفحهٔ اصلی.
 *
 * متن، لینک و تصویر هر اسلاید در دیتابیس است تا از پنل مدیریت عوض شود و
 * برای تغییر کمپین نیازی به انتشار دوبارهٔ فرانت‌اند نباشد.
 *
 * تیتر دو تکه است (`title` + `title_accent`) تا شکستن خطِ طراحی بدون
 * ذخیرهٔ HTML خام انجام شود؛ `theme` هم فقط از سه تم مجاز انتخاب می‌شود تا
 * کلاس‌های Tailwind در زمان build موجود باشند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hero_slides', function (Blueprint $table) {
            $table->id();
            $table->string('badge', 120)->nullable();      // برچسب کوچک بالای تیتر
            $table->string('title', 120);
            $table->string('title_accent', 120)->nullable();
            $table->text('text');
            $table->string('primary_label', 40);
            $table->string('primary_url', 200);
            $table->string('secondary_label', 40)->nullable();
            $table->string('secondary_url', 200)->nullable();
            $table->json('features');                   // حداکثر سه مزیت کوتاه؛ پیش‌فرض در مدل
            $table->string('image', 255)->nullable();      // آدرس تصویر یا /uploads/...
            $table->string('theme', 20)->default('primary');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hero_slides');
    }
};
