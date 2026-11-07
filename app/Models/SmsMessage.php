<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsMessage extends Model
{
    protected $fillable = [
        'phone', 'purpose', 'template', 'body', 'status', 'channel', 'provider', 'error', 'user_id',
    ];

    protected $hidden = ['body'];
}
