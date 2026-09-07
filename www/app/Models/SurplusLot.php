<?php

namespace App\Models;

use App\Enums\SurplusStatus;
use Database\Factories\SurplusLotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'producer_id', 'agricultural_product_id', 'quality_grade_id', 'quantity', 'unit',
    'origin_address', 'origin_city', 'origin_state', 'origin_country', 'harvest_date',
    'available_until', 'asking_price', 'minimum_price', 'donation_eligible',
    'accepted_logistics_modes', 'status', 'reserved_at',
])]
class SurplusLot extends Model
{
    /** @use HasFactory<SurplusLotFactory> */
    use HasFactory;

    public function producer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'producer_id');
    }

    public function agriculturalProduct(): BelongsTo
    {
        return $this->belongsTo(AgriculturalProduct::class);
    }

    public function qualityGrade(): BelongsTo
    {
        return $this->belongsTo(QualityGrade::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    public function trade(): HasOne
    {
        return $this->hasOne(Trade::class)->latestOfMany();
    }

    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class);
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'harvest_date' => 'date',
            'available_until' => 'immutable_datetime',
            'asking_price' => 'decimal:6',
            'minimum_price' => 'decimal:6',
            'donation_eligible' => 'boolean',
            'accepted_logistics_modes' => 'array',
            'status' => SurplusStatus::class,
            'reserved_at' => 'immutable_datetime',
        ];
    }
}
