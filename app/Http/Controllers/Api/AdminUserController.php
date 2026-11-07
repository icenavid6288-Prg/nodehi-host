<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Support\AdminPermission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * مدیریت کاربران فروشگاه.
 *
 * دیدن فهرست و جزئیات با مجوز `users` انجام می‌شود، اما تغییر نقش، وضعیت و
 * مجوزها فقط از مدیر اصلی (نقش `admin`) پذیرفته می‌شود تا اپراتور نتواند
 * سطح دسترسی خودش را بالا ببرد یا مدیر را قفل کند.
 */
class AdminUserController extends AdminController
{
    public function index(Request $request): JsonResponse
    {
        $query = User::query()->withCount('orders')->withSum('orders as orders_total', 'total');

        if ($q = trim((string) $request->query('q', ''))) {
            $query->where(function (Builder $inner) use ($q) {
                $inner->where('phone', 'like', "%{$q}%")
                    ->orWhere('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            });
        }

        if ($role = trim((string) $request->query('role', ''))) {
            $query->where('role', $role);
        }

        match ((string) $request->query('status', '')) {
            'active' => $query->where('is_active', true),
            'inactive' => $query->where('is_active', false),
            'admins' => $query->whereIn('role', ['admin', 'operator']),
            default => null,
        };

        $total = (clone $query)->count();
        $perPage = $this->perPage($request);
        $page = $this->page($request);

        $users = $query->orderByDesc('id')->forPage($page, $perPage)->get();

        return response()->json([
            'ok' => true,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'pageCount' => max((int) ceil($total / $perPage), 1),
            'items' => $users->map(fn (User $user) => $this->serialize($user))->all(),
            'permissions' => AdminPermission::catalog(),
        ]);
    }

    public function show(User $user): JsonResponse
    {
        $user->loadCount('orders');
        $user->loadSum('orders as orders_total', 'total');

        $orders = $user->orders()
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn ($order) => [
                'id' => $order->id,
                'orderNumber' => $order->order_number,
                'status' => $order->status,
                'total' => (int) $order->total,
                'createdAt' => optional($order->created_at)->toIso8601String(),
            ]);

        $addresses = $user->addresses()
            ->orderByDesc('id')
            ->get()
            ->map(fn ($address) => [
                'id' => $address->id,
                'title' => $address->title,
                'province' => $address->province,
                'city' => $address->city,
                'line' => $address->line,
                'postalCode' => $address->postal_code,
                'isDefault' => (bool) $address->is_default,
            ]);

        return response()->json([
            'ok' => true,
            'user' => $this->serialize($user) + [
                'email' => $user->email,
                'orders' => $orders->all(),
                'addresses' => $addresses->all(),
            ],
            'permissions' => AdminPermission::catalog(),
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();

        if (! $actor?->isSuperAdmin()) {
            return response()->json([
                'ok' => false,
                'error' => 'تغییر نقش و دسترسی کاربران فقط از مدیر اصلی پذیرفته می‌شود.',
            ], 403);
        }

        $data = $request->validate([
            'role' => ['sometimes', Rule::in(['customer', 'operator', 'admin'])],
            'isActive' => ['sometimes', 'boolean'],
            'loyaltyPoints' => ['sometimes', 'integer', 'min:0', 'max:10000000'],
            'loyaltyTier' => ['sometimes', 'string', 'max:30'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::in(AdminPermission::ALL)],
        ], [
            'role.in' => 'نقش انتخابی معتبر نیست.',
            'permissions.*.in' => 'یکی از مجوزهای انتخابی معتبر نیست.',
        ]);

        if ($user->is($actor) && array_key_exists('role', $data) && $data['role'] !== 'admin') {
            return response()->json([
                'ok' => false,
                'error' => 'نقش خودتان را نمی‌توانید از مدیر اصلی تغییر دهید.',
            ], 422);
        }

        if ($user->is($actor) && array_key_exists('isActive', $data) && $data['isActive'] === false) {
            return response()->json(['ok' => false, 'error' => 'حساب خودتان را نمی‌توانید غیرفعال کنید.'], 422);
        }

        // آخرین مدیر اصلی نباید حذف/غیرفعال شود تا پنل قفل نشود.
        $demoting = $user->role === 'admin' && array_key_exists('role', $data) && $data['role'] !== 'admin';
        $deactivating = $user->role === 'admin' && array_key_exists('isActive', $data) && $data['isActive'] === false;

        if ($demoting || $deactivating) {
            $otherAdmins = User::query()
                ->where('role', 'admin')
                ->where('is_active', true)
                ->whereKeyNot($user->id)
                ->exists();

            if (! $otherAdmins) {
                return response()->json([
                    'ok' => false,
                    'error' => 'حداقل یک مدیر اصلی فعال باید باقی بماند.',
                ], 422);
            }
        }

        DB::transaction(function () use ($user, $data) {
            foreach ([
                'role' => 'role',
                'isActive' => 'is_active',
                'loyaltyPoints' => 'loyalty_points',
                'loyaltyTier' => 'loyalty_tier',
            ] as $key => $column) {
                if (array_key_exists($key, $data)) {
                    $user->{$column} = $data[$key];
                }
            }

            $user->save();

            if (array_key_exists('permissions', $data)) {
                $user->adminPermissions()->delete();

                foreach (array_unique($data['permissions']) as $permission) {
                    $user->adminPermissions()->create(['permission' => $permission]);
                }
            }
        });

        $this->audit($request, 'user.updated', 'user', $user->id, [
            'fields' => array_keys($data),
            'role' => $user->role,
        ]);

        $user->refresh()->loadCount('orders');

        return response()->json([
            'ok' => true,
            'message' => 'کاربر به‌روزرسانی شد.',
            'user' => $this->serialize($user),
        ]);
    }

    private function serialize(User $user): array
    {
        return [
            'id' => $user->id,
            'phone' => $user->phone,
            'firstName' => $user->first_name,
            'lastName' => $user->last_name,
            'fullName' => $user->fullName(),
            'role' => $user->role,
            'isActive' => (bool) $user->is_active,
            'loyaltyPoints' => (int) $user->loyalty_points,
            'loyaltyTier' => $user->loyalty_tier,
            'ordersCount' => (int) ($user->orders_count ?? 0),
            'ordersTotal' => (int) ($user->orders_total ?? 0),
            'permissions' => $user->isAdmin() ? $user->effectivePermissions() : [],
            'lastLoginAt' => optional($user->last_login_at)->toIso8601String(),
            'createdAt' => optional($user->created_at)->toIso8601String(),
        ];
    }
}
