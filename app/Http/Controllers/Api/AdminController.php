<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

/**
 * پایهٔ کنترلرهای پنل مدیریت.
 *
 * هر تغییر مدیریتی در `audit_logs` ثبت می‌شود تا بعداً معلوم باشد چه کسی،
 * چه زمانی و کدام رکورد را تغییر داده است.
 */
abstract class AdminController extends Controller
{
    /** تعداد آیتم هر صفحه، بین ۱ تا ۱۰۰. */
    protected function perPage(Request $request, int $default = 20): int
    {
        return min(max((int) $request->query('perPage', $default), 1), 100);
    }

    protected function page(Request $request): int
    {
        return max((int) $request->query('page', 1), 1);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function audit(Request $request, string $action, string $entity, int|string|null $entityId, array $metadata = []): void
    {
        AuditLog::create([
            'actor_id' => $request->user()?->id,
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId === null ? null : (string) $entityId,
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE),
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);
    }
}
