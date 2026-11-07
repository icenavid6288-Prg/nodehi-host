<?php

namespace App\Models;

use App\Support\Secret;
use Illuminate\Database\Eloquent\Model;

/**
 * تنظیمات سایت به شکل کلید/مقدار.
 *
 * ردیف‌های `is_secret` رمزنگاری‌شده ذخیره می‌شوند و مقدار خام آن‌ها هیچ‌وقت
 * در پاسخ API نمی‌آید؛ خواندن آن‌ها فقط با `Setting::read` انجام شود.
 */
class Setting extends Model
{
    protected $fillable = ['key', 'group', 'value', 'is_secret'];

    protected $hidden = ['value'];

    protected function casts(): array
    {
        return ['is_secret' => 'boolean'];
    }

    /** مقدار مؤثر یک تنظیم؛ برای ردیف حساس، رمزگشایی‌شده. */
    public static function read(string $key, ?string $default = null): ?string
    {
        $setting = static::query()->where('key', $key)->first();

        if (! $setting || $setting->value === null) {
            return $default;
        }

        $value = $setting->is_secret ? Secret::open($setting->value) : $setting->value;

        return $value === null || $value === '' ? $default : $value;
    }

    /** نسخهٔ قابل نمایش در پنل؛ مقدار حساس ماسک می‌شود. */
    public static function masked(string $key): ?string
    {
        return Secret::mask(static::read($key));
    }

    public static function put(string $key, string $group, ?string $value, bool $isSecret = false): self
    {
        $stored = $value === null || trim($value) === '' ? null : ($isSecret ? Secret::seal($value) : $value);

        return static::updateOrCreate(
            ['key' => $key],
            ['group' => $group, 'value' => $stored, 'is_secret' => $isSecret],
        );
    }
}
