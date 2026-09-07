<?php

namespace App\Models;

use App\Enums\ShippingRequestStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'trade_id', 'origin_address', 'origin_city', 'origin_state', 'origin_country',
    'destination_address', 'destination_city', 'destination_state', 'destination_country',
    'quantity', 'unit', 'quotation_expires_at', 'status', 'selected_shipping_offer_id',
])]
class ShippingRequest extends Model
{
    use HasFactory;

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(ShippingOffer::class);
    }

    public function selectedOffer(): BelongsTo
    {
        return $this->belongsTo(ShippingOffer::class, 'selected_shipping_offer_id');
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'quotation_expires_at' => 'immutable_datetime',
            'status' => ShippingRequestStatus::class,
        ];
    }
}
