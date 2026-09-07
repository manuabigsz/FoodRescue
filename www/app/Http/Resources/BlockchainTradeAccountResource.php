<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlockchainTradeAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'cluster' => $this->cluster,
            'program_id' => $this->program_id,
            'mint' => $this->mint,
            'protocol_config_pda' => $this->protocol_config_pda,
            'token_decimals' => $this->token_decimals,
            'trade_pda' => $this->trade_pda,
            'vault_token_account' => $this->vault_token_account,
            'initialized_at' => $this->initialized_at?->toISOString(),
            'funded_at' => $this->funded_at?->toISOString(),
            'settled_at' => $this->settled_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'refunded_at' => $this->refunded_at?->toISOString(),
            'transactions' => BlockchainTransactionResource::collection($this->whenLoaded('transactions')),
        ];
    }
}
