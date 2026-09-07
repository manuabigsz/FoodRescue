<?php

namespace App\Models;

use Database\Factories\QualityGradeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'sort_order', 'active'])]
class QualityGrade extends Model
{
    /** @use HasFactory<QualityGradeFactory> */
    use HasFactory;

    public function surplusLots(): HasMany
    {
        return $this->hasMany(SurplusLot::class);
    }

    protected function casts(): array
    {
        return ['active' => 'boolean', 'sort_order' => 'integer'];
    }
}
