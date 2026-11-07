<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentGateway;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;

class SettingController extends Controller
{
    /** فقط تنظیمات غیرحساس به مرورگر می‌رود. */
    public function publicSettings(): JsonResponse
    {
        $settings = Setting::query()
            ->where('is_secret', false)
            ->whereIn('group', ['general', 'pages', 'integration'])
            ->get()
            ->mapWithKeys(fn (Setting $s) => [$s->key => $s->value]);

        return response()->json(['ok' => true, 'settings' => $settings]);
    }

    public function paymentMethods(): JsonResponse
    {
        $methods = PaymentGateway::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (PaymentGateway $g) => [
                'key' => $g->key,
                'label' => $g->label,
                'description' => $g->description,
                'sandbox' => $g->is_sandbox,
            ]);

        return response()->json(['ok' => true, 'items' => $methods]);
    }
}
