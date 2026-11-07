<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Article extends Model
{
    protected $fillable = [
        'slug', 'title', 'excerpt', 'body', 'category_slug', 'author',
        'published_at', 'content_updated_at', 'reading_minutes', 'keywords',
        'sort_order', 'is_active',
    ];

    /** پیش‌فرض ستون JSON (MySQL روی آن مقدار پیش‌فرض نمی‌پذیرد) */
    protected $attributes = [
        'keywords' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'body' => 'array',
            'keywords' => 'array',
            'published_at' => 'date',
            'content_updated_at' => 'date',
            'reading_minutes' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
