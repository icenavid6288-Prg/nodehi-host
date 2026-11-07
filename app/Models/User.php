<?php

namespace App\Models;

use App\Support\AdminPermission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * کاربر فروشگاه — ورود با OTP، بدون رمز عبور.
 */
class User extends Model
{
    protected $fillable = [
        'phone', 'first_name', 'last_name', 'email', 'role', 'is_active',
        'phone_verified_at', 'loyalty_points', 'loyalty_tier', 'last_login_at',
    ];

    protected $hidden = ['email'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'loyalty_points' => 'integer',
        ];
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(AuthSession::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function adminPermissions(): HasMany
    {
        return $this->hasMany(UserPermission::class);
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, ['admin', 'operator'], true);
    }

    /** مدیر اصلی؛ فقط او می‌تواند نقش و مجوز دیگران را تغییر دهد. */
    public function isSuperAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * مجوزهای مؤثر این کاربر در پنل.
     *
     * مدیر اصلی همه‌چیز دارد. اپراتوری که هیچ ردیف مجوزی ندارد، دسترسی
     * پیش‌فرض قدیمی (کاتالوگ و سفارش) را می‌گیرد تا بعد از این تغییر قفل نشود.
     *
     * @return string[]
     */
    public function effectivePermissions(): array
    {
        if ($this->isSuperAdmin()) {
            return AdminPermission::ALL;
        }

        if (! $this->isAdmin()) {
            return [];
        }

        $granted = $this->relationLoaded('adminPermissions')
            ? $this->adminPermissions->pluck('permission')->all()
            : $this->adminPermissions()->pluck('permission')->all();

        $granted = array_values(array_intersect($granted, AdminPermission::ALL));

        return $granted === [] ? AdminPermission::LEGACY_OPERATOR_DEFAULTS : $granted;
    }

    public function canAdmin(string $permission): bool
    {
        return in_array($permission, $this->effectivePermissions(), true);
    }

    public function fullName(): string
    {
        return trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? '')) ?: 'کاربر نودهی';
    }
}
