<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * اسلاید هیروی صفحهٔ اصلی.
 *
 * ترتیب نمایش با `sort_order` است و اسلایدهای غیرفعال در API عمومی برنمی‌گردند؛
 * اگر هیچ اسلاید فعالی نماند، فرانت‌اند به محتوای پیش‌فرض خودش برمی‌گردد.
 */
class HeroSlide extends Model
{
    public const THEMES = ['primary', 'secondary', 'accent'];

    protected $fillable = [
        'badge', 'title', 'title_accent', 'text',
        'primary_label', 'primary_url', 'secondary_label', 'secondary_url',
        'features', 'image', 'theme', 'sort_order', 'is_active',
    ];

    /** پیش‌فرض ستون JSON (MySQL روی آن مقدار پیش‌فرض نمی‌پذیرد) */
    protected $attributes = [
        'features' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
