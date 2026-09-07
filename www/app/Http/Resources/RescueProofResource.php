<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RescueProofResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trade_id' => $this->trade_id,
            'program_id' => $this->program_id,
            'proof_pda' => $this->proof_pda,
            'signature' => $this->signature,
            'slot' => $this->slot,
            'metadata_hash' => $this->metadata_hash,
            'metadata' => $this->metadata,
            'confirmed_at' => $this->confirmed_at?->toISOString(),
        ];
    }
}
