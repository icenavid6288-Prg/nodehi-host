<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyInstallment extends Model
{
    protected $fillable = [
        'months', 'label', 'interest_label', 'note', 'is_featured', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'months' => 'integer',
            'is_featured' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
