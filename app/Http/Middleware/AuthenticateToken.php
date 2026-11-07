<?php

namespace App\Http\Middleware;

use App\Models\AuthSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * احراز هویت با توکن Bearer.
 *
 * توکن خام هرگز در دیتابیس ذخیره نمی‌شود؛ فقط SHA-256 آن ذخیره می‌شود تا
 * نسخه پشتیبان دیتابیس، نشست‌های فعال را لو ندهد.
 */
class AuthenticateToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json(['ok' => false, 'error' => 'احراز هویت لازم است.'], 401);
        }

        $session = AuthSession::resolveToken($token);

        if (! $session || ! $session->user || ! $session->user->is_active) {
            return response()->json(['ok' => false, 'error' => 'نشست منقضی شده است.'], 401);
        }

        $request->setUserResolver(fn () => $session->user);
        $request->attributes->set('auth_session', $session);

        return $next($request);
    }
}
