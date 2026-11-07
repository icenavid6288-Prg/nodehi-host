<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutomationEvent extends Model
{
    protected $fillable = [
        'event', 'payload', 'status', 'attempts', 'response_status', 'last_error', 'sent_at',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'attempts' => 'integer'];
    }
}
