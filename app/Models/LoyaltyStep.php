<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyStep extends Model
{
    protected $fillable = ['step', 'title', 'description', 'sort_order'];

    protected function casts(): array
    {
        return [
            'step' => 'integer',
            'sort_order' => 'integer',
        ];
    }
}
