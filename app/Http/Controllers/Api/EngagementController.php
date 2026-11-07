<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Models\Product;
use App\Models\StockAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EngagementController extends Controller
{
    public function contact(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'phone' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:120'],
            'subject' => ['required', 'string', 'max:120'],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $message = ContactMessage::create($data + ['status' => 'new']);

        return response()->json([
            'ok' => true,
            'message' => 'پیام شما ثبت شد؛ کارشناسان نودهی در اولین فرصت تماس می‌گیرند.',
            'id' => $message->id,
        ], 201);
    }

    public function stockAlert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'productSlug' => ['required', 'string', 'exists:products,slug'],
            'phone' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:120'],
        ]);

        $alert = StockAlert::updateOrCreate(
            ['product_slug' => $data['productSlug'], 'phone' => $data['phone']],
            ['email' => $data['email'] ?? null, 'user_id' => $request->user()?->id]
        );

        return response()->json([
            'ok' => true,
            'message' => 'به محض موجود شدن این کالا به شما اطلاع می‌دهیم.',
            'id' => $alert->id,
        ], 201);
    }
}
