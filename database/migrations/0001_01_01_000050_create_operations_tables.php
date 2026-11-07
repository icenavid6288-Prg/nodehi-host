<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تنظیمات، پیامک، درگاه پرداخت، اتوماسیون و لاگ حسابرسی.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('group'); // general|pages|sms|payments|automation
            $table->text('value')->nullable();
            $table->boolean('is_secret')->default(false);
            $table->timestamps();

            $table->index('group');
        });

        Schema::create('sms_messages', function (Blueprint $table) {
            $table->id();
            $table->string('phone');
            $table->string('purpose')->default('notification'); // otp|notification|test
            $table->string('template')->nullable();
            $table->text('body')->nullable();
            $table->string('status')->default('sent'); // sent|failed
            $table->string('channel');
            $table->string('provider')->default('kavenegar');
            $table->text('error')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index('phone');
            $table->index('status');
            $table->index('created_at');
        });

        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // zarinpal|idpay|...|cod|installment
            $table->string('label');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('is_sandbox')->default(true);
            $table->string('merchant_id')->nullable();
            $table->text('api_key')->nullable(); // رمزنگاری‌شده
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::create('automation_events', function (Blueprint $table) {
            $table->id();
            $table->string('event');
            $table->longText('payload');
            $table->string('status')->default('sent'); // sent|failed|skipped
            $table->unsignedInteger('attempts')->default(1);
            $table->integer('response_status')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['event', 'status']);
            $table->index('created_at');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('entity');
            $table->string('entity_id')->nullable();
            $table->longText('metadata')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->index(['entity', 'entity_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('automation_events');
        Schema::dropIfExists('payment_gateways');
        Schema::dropIfExists('sms_messages');
        Schema::dropIfExists('settings');
    }
};
