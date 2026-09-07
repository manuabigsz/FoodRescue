<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlockchainTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->type->value,
            'signature' => $this->signature,
            'slot' => $this->slot,
            'status' => $this->status->value,
            'confirmed_at' => $this->confirmed_at?->toISOString(),
        ];
    }
}
