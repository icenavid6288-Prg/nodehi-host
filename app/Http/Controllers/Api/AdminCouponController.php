<?php

namespace App\Http\Controllers\Api;

use App\Models\Coupon;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * مدیریت کدهای تخفیف.
 *
 * کد همیشه با حروف بزرگ ذخیره می‌شود تا ورودی کاربر در تسویه، مستقل از
 * بزرگی و کوچکی حروف، همان کد را پیدا کند.
 */
class AdminCouponController extends AdminController
{
    public function index(Request $request): JsonResponse
    {
        $query = Coupon::query();

        if ($q = trim((string) $request->query('q', ''))) {
            $query->where(function (Builder $inner) use ($q) {
                $inner->where('code', 'like', "%{$q}%")->orWhere('label', 'like', "%{$q}%");
            });
        }

        match ((string) $request->query('status', '')) {
            'active' => $query->where('is_active', true),
            'inactive' => $query->where('is_active', false),
            'expired' => $query->whereNotNull('expires_at')->where('expires_at', '<', now()),
            default => null,
        };

        $total = (clone $query)->count();
        $perPage = $this->perPage($request, 30);
        $page = $this->page($request);

        $items = $query->orderByDesc('id')->forPage($page, $perPage)->get();

        return response()->json([
            'ok' => true,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'pageCount' => max((int) ceil($total / $perPage), 1),
            'items' => $items->map(fn (Coupon $c) => $this->serialize($c))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code', '')))]);
        $data = $request->validate($this->rules(), $this->messages());
        $this->ensureDateOrder($data);

        $coupon = Coupon::create($this->columns($data));
        // مقادیر پیش‌فرض دیتابیس (used_count) را در پاسخ هم برگردان.
        $coupon->refresh();

        $this->audit($request, 'coupon.created', 'coupon', $coupon->id, ['code' => $coupon->code]);

        return response()->json(['ok' => true, 'coupon' => $this->serialize($coupon)], 201);
    }

    public function update(Request $request, Coupon $coupon): JsonResponse
    {
        if ($request->has('code')) {
            $request->merge(['code' => strtoupper(trim((string) $request->input('code', '')))]);
        }

        $data = $request->validate($this->rules($coupon), $this->messages());
        $this->ensureDateOrder($data, $coupon);

        if (array_key_exists('maxUses', $data)
            && $data['maxUses'] !== null
            && $data['maxUses'] < $coupon->used_count) {
            throw ValidationException::withMessages([
                'maxUses' => 'سقف استفاده نمی‌تواند کمتر از تعداد استفاده‌شدهٔ فعلی باشد.',
            ]);
        }

        $coupon->fill($this->columns($data));
        $coupon->save();

        $this->audit($request, 'coupon.updated', 'coupon', $coupon->id, [
            'code' => $coupon->code,
            'fields' => array_keys($data),
        ]);

        return response()->json(['ok' => true, 'coupon' => $this->serialize($coupon->fresh())]);
    }

    public function destroy(Request $request, Coupon $coupon): JsonResponse
    {
        $code = $coupon->code;
        $coupon->delete();

        $this->audit($request, 'coupon.deleted', 'coupon', $coupon->id, ['code' => $code]);

        return response()->json(['ok' => true, 'message' => 'کد تخفیف حذف شد.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?Coupon $coupon = null): array
    {
        $creating = $coupon === null;
        $unique = Rule::unique('coupons', 'code');
        if ($coupon) {
            $unique->ignore($coupon->id);
        }

        return [
            'code' => [
                $creating ? 'required' : 'sometimes',
                'string',
                'max:40',
                'regex:/^[A-Z0-9_-]+$/',
                $unique,
            ],
            'label' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'percent' => [$creating ? 'required' : 'sometimes', 'integer', 'between:1,100'],
            'minSubtotal' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000000'],
            'maxUses' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'startsAt' => ['sometimes', 'nullable', 'date'],
            'expiresAt' => ['sometimes', 'nullable', 'date'],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'code.regex' => 'کد تخفیف فقط می‌تواند شامل حروف لاتین، رقم، خط تیره و زیرخط باشد.',
            'code.unique' => 'این کد تخفیف قبلاً ساخته شده است.',
            'percent.between' => 'درصد تخفیف باید بین ۱ تا ۱۰۰ باشد.',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function ensureDateOrder(array $data, ?Coupon $coupon = null): void
    {
        $start = array_key_exists('startsAt', $data)
            ? $data['startsAt']
            : optional($coupon?->starts_at)->toDateString();
        $end = array_key_exists('expiresAt', $data)
            ? $data['expiresAt']
            : optional($coupon?->expires_at)->toDateString();

        if ($start && $end && Carbon::parse($end)->lt(Carbon::parse($start))) {
            throw ValidationException::withMessages([
                'expiresAt' => 'تاریخ پایان نمی‌تواند پیش از تاریخ شروع باشد.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function columns(array $data): array
    {
        $columns = [];

        foreach ([
            'code' => 'code',
            'label' => 'label',
            'percent' => 'percent',
            'minSubtotal' => 'min_subtotal',
            'maxUses' => 'max_uses',
            'isActive' => 'is_active',
        ] as $key => $column) {
            if (array_key_exists($key, $data)) {
                $columns[$column] = $data[$key];
            }
        }

        if (array_key_exists('startsAt', $data)) {
            $columns['starts_at'] = $this->toDate($data['startsAt'], false);
        }
        if (array_key_exists('expiresAt', $data)) {
            $columns['expires_at'] = $this->toDate($data['expiresAt'], true);
        }

        return $columns;
    }

    private function toDate(?string $value, bool $endOfDay): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = Carbon::parse($value);

        // ورودی تاریخ‌تنها از فرم ادمین می‌آید؛ پایان روز ذخیره شود تا روز
        // آخر اعتبار کد از دست نرود.
        if (strlen($value) <= 10) {
            return $endOfDay ? $date->endOfDay() : $date->startOfDay();
        }

        return $date;
    }

    private function serialize(Coupon $c): array
    {
        return [
            'id' => $c->id,
            'code' => $c->code,
            'label' => $c->label,
            'percent' => $c->percent,
            'minSubtotal' => $c->min_subtotal,
            'maxUses' => $c->max_uses,
            'usedCount' => $c->used_count,
            'startsAt' => optional($c->starts_at)->toDateString(),
            'expiresAt' => optional($c->expires_at)->toDateString(),
            'isActive' => (bool) $c->is_active,
            'createdAt' => optional($c->created_at)->toIso8601String(),
        ];
    }
}
