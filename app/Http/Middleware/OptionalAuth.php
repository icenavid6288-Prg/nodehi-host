<?php

namespace App\Http\Middleware;

use App\Models\AuthSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * احراز هویت اختیاری.
 *
 * برای مسیرهایی مثل تسویه، ثبت نظر و هشدار موجودی که مهمان هم می‌تواند
 * استفاده کند: اگر توکن معتبر بود کاربر را می‌شناسیم، اگر نبود درخواست
 * رد نمی‌شود. بدین ترتیب سفارش مهمان با حساب کاربر قاطی نمی‌شود.
 */
class OptionalAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token) {
            $session = AuthSession::resolveToken($token);

            if ($session?->user?->is_active) {
                $request->setUserResolver(fn () => $session->user);
                $request->attributes->set('auth_session', $session);
            }
        }

        return $next($request);
    }
}
