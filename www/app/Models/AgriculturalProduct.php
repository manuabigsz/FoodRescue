<?php

namespace App\Models;

use Database\Factories\AgriculturalProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'active'])]
class AgriculturalProduct extends Model
{
    /** @use HasFactory<AgriculturalProductFactory> */
    use HasFactory;

    public function surplusLots(): HasMany
    {
        return $this->hasMany(SurplusLot::class);
    }

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
