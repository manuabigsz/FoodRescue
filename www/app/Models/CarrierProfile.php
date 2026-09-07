<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'company_name', 'document_number', 'phone', 'contact_name', 'country', 'state',
    'city', 'address_line', 'postal_code', 'service_regions', 'vehicle_types', 'max_capacity_kg',
])]
class CarrierProfile extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'service_regions' => 'array',
            'vehicle_types' => 'array',
            'max_capacity_kg' => 'decimal:3',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
