<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlockchainProtocolConfigResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'cluster' => $this->cluster,
            'program_id' => $this->program_id,
            'authority_wallet' => $this->authority_wallet,
            'treasury_wallet' => $this->treasury_wallet,
            'mint' => $this->mint,
            'config_pda' => $this->config_pda,
            'version' => $this->version,
            'confirmed_at' => $this->confirmed_at?->toISOString(),
        ];
    }
}
