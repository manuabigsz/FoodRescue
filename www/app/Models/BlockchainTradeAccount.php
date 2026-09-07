<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'trade_id', 'cluster', 'program_id', 'mint', 'protocol_config_pda', 'token_decimals', 'trade_pda',
    'vault_token_account', 'initialized_at', 'funded_at', 'settled_at', 'cancelled_at', 'refunded_at',
])]
class BlockchainTradeAccount extends Model
{
    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BlockchainTransaction::class, 'trade_id', 'trade_id');
    }

    protected function casts(): array
    {
        return [
            'token_decimals' => 'integer',
            'initialized_at' => 'immutable_datetime',
            'funded_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'refunded_at' => 'immutable_datetime',
        ];
    }
}
