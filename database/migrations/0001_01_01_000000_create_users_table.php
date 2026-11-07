<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * کاربران فروشگاه نودهی.
 *
 * ورود فقط با شماره همراه و کد یک‌بارمصرف انجام می‌شود؛ رمز عبور وجود ندارد.
 * ستون password عمداً حذف شده تا هیچ مسیر ورود رمزمحوری باقی نماند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->unique();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('role')->default('customer'); // customer | operator | accountant | admin
            $table->boolean('is_active')->default(true);
            $table->timestamp('phone_verified_at')->nullable();
            $table->integer('loyalty_points')->default(0);
            $table->string('loyalty_tier')->default('برنزی');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->index('role');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
