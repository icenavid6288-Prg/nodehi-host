<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'order_number', 'user_id', 'status', 'payment_method', 'shipping_method',
        'coupon_code', 'subtotal', 'discount', 'shipping', 'total',
        'customer_first_name', 'customer_last_name', 'customer_phone', 'customer_email',
        'shipping_province', 'shipping_city', 'shipping_line', 'shipping_postal_code',
        'tracking_code', 'note', 'idempotency_key',
        'payment_authority', 'payment_redirect_url', 'payment_ref_id', 'payment_card_pan',
        'paid_at', 'shipped_at', 'delivered_at', 'cancelled_at',
    ];

    protected $hidden = ['idempotency_key'];

    protected function casts(): array
    {
        return [
            'subtotal' => 'integer',
            'discount' => 'integer',
            'shipping' => 'integer',
            'total' => 'integer',
            'paid_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customerName(): string
    {
        return trim($this->customer_first_name . ' ' . $this->customer_last_name);
    }
}
