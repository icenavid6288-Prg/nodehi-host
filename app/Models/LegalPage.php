<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LegalPage extends Model
{
    protected $fillable = [
        'slug', 'title', 'intro', 'sections', 'content_updated_at', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sections' => 'array',
            'content_updated_at' => 'date',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
