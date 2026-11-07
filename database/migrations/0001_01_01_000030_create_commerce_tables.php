<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سفارش‌ها و کدهای تخفیف.
 *
 * نام و قیمت هر قلم در زمان ثبت کپی می‌شود تا فاکتور قدیمی با تغییر قیمت
 * کاتالوگ عوض نشود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('label');
            $table->unsignedTinyInteger('percent');
            $table->unsignedInteger('min_subtotal')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('pending'); // pending|paid|processing|shipped|delivered|cancelled
            $table->string('payment_method');             // online|cod|installment
            $table->string('shipping_method');
            $table->string('coupon_code')->nullable();

            $table->unsignedInteger('subtotal');
            $table->unsignedInteger('discount')->default(0);
            $table->unsignedInteger('shipping')->default(0);
            $table->unsignedInteger('total');

            $table->string('customer_first_name');
            $table->string('customer_last_name');
            $table->string('customer_phone');
            $table->string('customer_email')->nullable();
            $table->string('shipping_province');
            $table->string('shipping_city');
            $table->text('shipping_line');
            $table->string('shipping_postal_code')->nullable();

            $table->string('tracking_code')->nullable();
            $table->text('note')->nullable();
            $table->string('idempotency_key')->nullable()->unique();

            $table->string('payment_authority')->nullable()->unique();
            $table->text('payment_redirect_url')->nullable();
            $table->string('payment_ref_id')->nullable();
            $table->string('payment_card_pan')->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('created_at');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('product_slug');
            $table->string('product_name');
            $table->unsignedInteger('unit_price');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('line_total');
            $table->timestamps();

            $table->index('product_slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('coupons');
    }
};
