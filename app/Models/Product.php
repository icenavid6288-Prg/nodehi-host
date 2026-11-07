<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'slug', 'name', 'category_slug', 'brand_slug', 'price', 'compare_at_price',
        'stock', 'rating', 'review_count', 'short_description', 'description',
        'highlights', 'specs', 'attributes', 'images', 'tags', 'related_slugs',
        'video_url', 'published_at', 'is_active',
    ];

    /**
     * ستون‌های TEXT/JSON در MySQL مقدار پیش‌فرض نمی‌پذیرند؛ این پیش‌فرض‌ها
     * همان مقادیر قبلیِ سطح دیتابیس را برای همهٔ درایورها بازمی‌سازند.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'description' => '',
        'highlights' => '[]',
        'specs' => '[]',
        'attributes' => '{}',
        'images' => '[]',
        'tags' => '[]',
        'related_slugs' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'highlights' => 'array',
            'specs' => 'array',
            'attributes' => 'array',
            'images' => 'array',
            'tags' => 'array',
            'related_slugs' => 'array',
            'published_at' => 'datetime',
            'is_active' => 'boolean',
            'price' => 'integer',
            'compare_at_price' => 'integer',
            'stock' => 'integer',
            'rating' => 'float',
            'review_count' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeInStock(Builder $query): Builder
    {
        return $query->where('stock', '>', 0);
    }

    /** درصد تخفیف محاسبه‌شده از قیمت پیش از تخفیف */
    public function discountPercent(): int
    {
        if (! $this->compare_at_price || $this->compare_at_price <= $this->price) {
            return 0;
        }

        return (int) round((($this->compare_at_price - $this->price) / $this->compare_at_price) * 100);
    }
}
