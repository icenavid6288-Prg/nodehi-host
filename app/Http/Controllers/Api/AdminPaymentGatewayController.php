<?php

namespace App\Http\Controllers\Api;

use App\Models\PaymentGateway;
use App\Support\Secret;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * اتصال درگاه‌های پرداخت از پنل.
 *
 * کلید پذیرنده و کلید API رمزنگاری‌شده ذخیره می‌شوند و در پاسخ فقط ماسک
 * برمی‌گردند. اگر کاربر همان ماسک را برگرداند، مقدار قبلی دست‌نخورده می‌ماند؛
 * برای پاک‌کردن کلید، رشتهٔ خالی بفرستید.
 */
class AdminPaymentGatewayController extends AdminController
{
    public function index(): JsonResponse
    {
        $gateways = PaymentGateway::query()->orderBy('sort_order')->orderBy('id')->get();

        return response()->json([
            'ok' => true,
            'items' => $gateways->map(fn (PaymentGateway $gateway) => $this->serialize($gateway))->all(),
        ]);
    }

    public function update(Request $request, PaymentGateway $gateway): JsonResponse
    {
        $data = $request->validate([
            'label' => ['sometimes', 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:300'],
            'isActive' => ['sometimes', 'boolean'],
            'isSandbox' => ['sometimes', 'boolean'],
            'merchantId' => ['sometimes', 'nullable', 'string', 'max:200'],
            'apiKey' => ['sometimes', 'nullable', 'string', 'max:300'],
            'sortOrder' => ['sometimes', 'integer', 'between:0,999'],
        ], [
            'label.max' => 'عنوان درگاه حداکثر ۸۰ کاراکتر است.',
        ]);

        foreach (['label' => 'label', 'description' => 'description'] as $key => $column) {
            if (array_key_exists($key, $data)) {
                $gateway->{$column} = $data[$key];
            }
        }

        foreach (['isActive' => 'is_active', 'isSandbox' => 'is_sandbox', 'sortOrder' => 'sort_order'] as $key => $column) {
            if (array_key_exists($key, $data)) {
                $gateway->{$column} = $data[$key];
            }
        }

        foreach (['merchantId' => 'merchant_id', 'apiKey' => 'api_key'] as $key => $column) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];

            // ماسک بدون تغییر یعنی «همان مقدار قبلی».
            if (Secret::isMask($value)) {
                continue;
            }

            $gateway->{$column} = trim((string) $value) === '' ? null : $value;
        }

        $gateway->save();

        $this->audit($request, 'payment_gateway.updated', 'payment_gateway', $gateway->id, [
            'key' => $gateway->key,
            'fields' => array_keys($data),
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'تنظیمات درگاه ذخیره شد.',
            'gateway' => $this->serialize($gateway->fresh()),
        ]);
    }

    private function serialize(PaymentGateway $gateway): array
    {
        return [
            'id' => $gateway->id,
            'key' => $gateway->key,
            'label' => $gateway->label,
            'description' => $gateway->description,
            'isActive' => (bool) $gateway->is_active,
            'isSandbox' => (bool) $gateway->is_sandbox,
            'merchantId' => $gateway->maskedMerchantId(),
            'hasMerchantId' => trim((string) $gateway->merchant_id) !== '',
            'apiKey' => $gateway->maskedApiKey(),
            'hasApiKey' => trim((string) $gateway->api_key) !== '',
            'sortOrder' => (int) $gateway->sort_order,
            'updatedAt' => optional($gateway->updated_at)->toIso8601String(),
        ];
    }
}
