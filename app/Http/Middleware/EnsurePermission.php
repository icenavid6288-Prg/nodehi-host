<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * کنترل دسترسی بخش‌های پنل: `perm:users` یا `perm:products,orders`.
 *
 * اگر کاربر حتی یکی از مجوزهای خواسته‌شده را داشته باشد عبور می‌کند. نقش
 * `admin` همیشه همه‌چیز دارد (بخش User::effectivePermissions).
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isAdmin()) {
            return response()->json(['ok' => false, 'error' => 'دسترسی مجاز نیست.'], 403);
        }

        foreach ($permissions as $permission) {
            if ($user->canAdmin($permission)) {
                return $next($request);
            }
        }

        return response()->json([
            'ok' => false,
            'error' => 'برای این بخش مجوز ندارید. از مدیر اصلی بخواهید دسترسی بدهد.',
        ], 403);
    }
}
