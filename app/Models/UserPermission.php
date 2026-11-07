<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** یک مجوز پنل برای یک کاربر (بخش‌های مجاز پنل مدیریت). */
class UserPermission extends Model
{
    protected $fillable = ['user_id', 'permission'];
}
