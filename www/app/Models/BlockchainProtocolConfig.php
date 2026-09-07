<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['cluster', 'program_id', 'authority_wallet', 'treasury_wallet', 'mint', 'config_pda', 'version', 'confirmed_at'])]
class BlockchainProtocolConfig extends Model
{
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'confirmed_at' => 'datetime',
        ];
    }
}
