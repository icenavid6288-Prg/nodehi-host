<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    /**
     * اعتبارسنجی کد تخفیف برای پیش‌نمایش در صفحهٔ تسویه.
     *
     * این نتیجه فقط برای نمایش است؛ هنگام ثبت سفارش همان قواعد دوباره
     * روی سرور بررسی می‌شوند و مقدار مرجع، مبلغ ذخیره‌شده در سفارش است.
     */
    public function validateCoupon(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'subtotal' => ['required', 'integer', 'min:0'],
        ]);

        $code = strtoupper(trim($data['code']));
        $coupon = Coupon::query()->whereRaw('UPPER(code) = ?', [$code])->first();

        if (! $coupon || ! $coupon->isUsableFor((int) $data['subtotal'])) {
            return response()->json([
                'ok' => false,
                'error' => 'این کد تخفیف معتبر نیست یا شرایط استفاده از آن برقرار نیست.',
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'coupon' => [
                'code' => $coupon->code,
                'label' => $coupon->label,
                'percent' => $coupon->percent,
                'discount' => (int) floor($data['subtotal'] * $coupon->percent / 100),
            ],
        ]);
    }
}
