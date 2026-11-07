<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تعامل با مشتری: نظر محصول، پیام تماس و درخواست اطلاع موجودی.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->string('product_slug');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author');
            $table->unsignedTinyInteger('rating');
            $table->string('title');
            $table->text('body');
            $table->string('status')->default('pending'); // pending|approved|rejected
            $table->timestamps();

            $table->unique(['user_id', 'product_slug']);
            $table->index(['product_slug', 'status']);
        });

        Schema::create('contact_messages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone');
            $table->string('email')->nullable();
            $table->string('subject');
            $table->text('message');
            $table->string('status')->default('new'); // new|in_progress|resolved
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('stock_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('product_slug');
            $table->string('phone');
            $table->string('email')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['product_slug', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_alerts');
        Schema::dropIfExists('contact_messages');
        Schema::dropIfExists('reviews');
    }
};
