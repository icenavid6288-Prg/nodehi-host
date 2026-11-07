<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * نشست ورود — فقط هش توکن ذخیره می‌شود.
 */
class AuthSession extends Model
{
    protected $fillable = ['token_hash', 'user_id', 'expires_at', 'user_agent', 'ip'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * نشست معتبر برای یک توکن خام؛ فقط هش توکن در دیتابیس است.
     * این متد تنها نقطهٔ تفسیر توکن است تا احراز هویت اجباری و اختیاری
     * هر دو یک قاعده داشته باشند.
     */
    public static function resolveToken(string $token): ?self
    {
        return static::with('user')
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->first();
    }
}
