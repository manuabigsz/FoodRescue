<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'trade_id', 'program_id', 'proof_pda', 'signature', 'slot', 'metadata_hash', 'confirmed_at', 'metadata',
])]
class RescueProof extends Model
{
    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    protected function casts(): array
    {
        return [
            'slot' => 'integer',
            'confirmed_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }
}
