<?php

namespace App\Models;

use App\Enums\OfferStatus;
use Database\Factories\OfferFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['surplus_lot_id', 'buyer_id', 'amount', 'status', 'expires_at', 'responded_at'])]
class Offer extends Model
{
    /** @use HasFactory<OfferFactory> */
    use HasFactory;

    public function surplusLot(): BelongsTo
    {
        return $this->belongsTo(SurplusLot::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function trade(): HasOne
    {
        return $this->hasOne(Trade::class, 'accepted_offer_id');
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:6',
            'status' => OfferStatus::class,
            'expires_at' => 'immutable_datetime',
            'responded_at' => 'immutable_datetime',
        ];
    }
}
