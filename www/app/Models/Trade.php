<?php

namespace App\Models;

use App\Enums\TradeStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'surplus_lot_id', 'producer_id', 'buyer_id', 'cancelled_by_id', 'cancellation_reason', 'accepted_offer_id', 'product_amount',
    'shipping_amount', 'protocol_fee', 'status', 'is_donation', 'donation_accepted_at', 'payment_expires_at',
    'ready_for_pickup_at', 'picked_up_at', 'delivered_at', 'proof_pending_at', 'completed_at', 'cancelled_at', 'blockchain_preparation',
])]
class Trade extends Model
{
    public function surplusLot(): BelongsTo
    {
        return $this->belongsTo(SurplusLot::class);
    }

    public function producer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'producer_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_id');
    }

    public function acceptedOffer(): BelongsTo
    {
        return $this->belongsTo(Offer::class, 'accepted_offer_id');
    }

    public function shippingRequest(): HasOne
    {
        return $this->hasOne(ShippingRequest::class);
    }

    public function blockchainAccount(): HasOne
    {
        return $this->hasOne(BlockchainTradeAccount::class);
    }

    public function rescueProof(): HasOne
    {
        return $this->hasOne(RescueProof::class);
    }

    public function blockchainTransactions(): HasMany
    {
        return $this->hasMany(BlockchainTransaction::class);
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class);
    }

    protected function casts(): array
    {
        return [
            'blockchain_preparation' => 'array',
            'product_amount' => 'decimal:6',
            'shipping_amount' => 'decimal:6',
            'protocol_fee' => 'decimal:6',
            'status' => TradeStatus::class,
            'is_donation' => 'boolean',
            'donation_accepted_at' => 'immutable_datetime',
            'payment_expires_at' => 'immutable_datetime',
            'ready_for_pickup_at' => 'immutable_datetime',
            'picked_up_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'proof_pending_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }
}
