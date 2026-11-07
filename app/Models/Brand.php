<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    protected $fillable = [
        'slug', 'name', 'name_en', 'origin', 'description',
        'featured', 'sort_order', 'is_active',
    ];

    /** پیش‌فرض ستون TEXT (MySQL روی آن مقدار پیش‌فرض نمی‌پذیرد) */
    protected $attributes = [
        'description' => '',
    ];

    protected function casts(): array
    {
        return [
            'featured' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
