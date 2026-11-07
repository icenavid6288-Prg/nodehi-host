<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جدول نشست‌ها.
 *
 * نصب‌کنندهٔ هاست `SESSION_DRIVER=database` را در `.env` می‌نویسد (چون روی
 * هاست اشتراکی نیازی به دسترسی نوشتن در مسیر خاص نیست)؛ بدون این جدول هر
 * درخواستی که session را شروع کند با خطای «no such table: sessions» ۵۰۰
 * می‌داد. ورود کاربران با توکن Bearer است و به جدول نشست وابسته نیست.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
