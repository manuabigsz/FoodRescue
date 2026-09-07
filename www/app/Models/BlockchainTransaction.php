<?php

namespace App\Models;

use App\Enums\BlockchainTransactionStatus;
use App\Enums\BlockchainTransactionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'trade_id', 'type', 'signature', 'slot', 'status', 'confirmed_at', 'metadata',
])]
class BlockchainTransaction extends Model
{
    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    protected function casts(): array
    {
        return [
            'type' => BlockchainTransactionType::class,
            'status' => BlockchainTransactionStatus::class,
            'slot' => 'integer',
            'confirmed_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }
}
