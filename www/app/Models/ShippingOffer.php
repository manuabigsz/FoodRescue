<?php

namespace App\Models;

use App\Enums\ShippingOfferStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'shipping_request_id', 'carrier_id', 'amount', 'pickup_at', 'estimated_delivery_at',
    'expires_at', 'status',
])]
class ShippingOffer extends Model
{
    use HasFactory;

    public function shippingRequest(): BelongsTo
    {
        return $this->belongsTo(ShippingRequest::class);
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'carrier_id');
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:6',
            'pickup_at' => 'immutable_datetime',
            'estimated_delivery_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'status' => ShippingOfferStatus::class,
        ];
    }
}
