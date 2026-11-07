<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyTier extends Model
{
    protected $fillable = [
        'name', 'requirement_label', 'discount_label', 'points_label', 'perks', 'sort_order', 'is_active',
    ];

    /** پیش‌فرض ستون JSON (MySQL روی آن مقدار پیش‌فرض نمی‌پذیرد) */
    protected $attributes = [
        'perks' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'perks' => 'array',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
