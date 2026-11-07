<?php

namespace App\Models;

use App\Support\Secret;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * درگاه‌های پرداخت.
 *
 * `merchant_id` و `api_key` رمزنگاری‌شده ذخیره می‌شوند؛ خواندن آن‌ها از مدل
 * مقدار رمزگشایی‌شده می‌دهد و ذخیره، خودکار رمزنگاری می‌کند. ردیف‌های قدیمی
 * که متن ساده دارند بدون خطا خوانده می‌شوند.
 */
class PaymentGateway extends Model
{
    protected $fillable = [
        'key', 'label', 'description', 'is_active', 'is_sandbox', 'merchant_id', 'api_key', 'sort_order',
    ];

    protected $hidden = ['api_key', 'merchant_id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_sandbox' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected function merchantId(): Attribute
    {
        return Attribute::make(
            get: static fn (?string $value) => Secret::open($value),
            set: static fn (?string $value) => Secret::seal($value),
        );
    }

    protected function apiKey(): Attribute
    {
        return Attribute::make(
            get: static fn (?string $value) => Secret::open($value),
            set: static fn (?string $value) => Secret::seal($value),
        );
    }

    public function maskedMerchantId(): ?string
    {
        return Secret::mask($this->merchant_id);
    }

    public function maskedApiKey(): ?string
    {
        return Secret::mask($this->api_key);
    }
}
