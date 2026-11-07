<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مجوزهای دانه‌ای پنل مدیریت.
 *
 * هر ردیف یعنی «این کاربر به این بخش دسترسی دارد». نقش `admin` به این جدول
 * کاری ندارد و همیشه همهٔ بخش‌ها را می‌بیند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('permission', 40);
            $table->timestamps();

            $table->unique(['user_id', 'permission']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permissions');
    }
};
