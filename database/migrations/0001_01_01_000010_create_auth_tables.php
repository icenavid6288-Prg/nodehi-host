<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * احراز هویت: کد یک‌بارمصرف، نشست توکن‌محور و آدرس‌های کاربر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->string('phone');
            $table->string('code_hash');
            $table->string('purpose')->default('login'); // login | verify_phone
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['phone', 'purpose']);
            $table->index('expires_at');
        });

        // نشست ورود کاربر — توکن خام فقط در کوکی/مرورگر، در دیتابیس هش می‌شود.
        Schema::create('auth_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->string('user_agent')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index('expires_at');
        });

        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title')->default('خانه');
            $table->string('receiver');
            $table->string('phone');
            $table->string('province');
            $table->string('city');
            $table->text('line');
            $table->string('postal_code')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
        Schema::dropIfExists('auth_sessions');
        Schema::dropIfExists('otp_codes');
    }
};
