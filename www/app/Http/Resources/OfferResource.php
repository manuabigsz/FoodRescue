<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OfferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'surplus_lot_id' => $this->surplus_lot_id,
            'buyer' => new PublicUserResource($this->whenLoaded('buyer')),
            'amount' => $this->amount,
            'status' => $this->status->value,
            'expires_at' => $this->expires_at?->toISOString(),
            'responded_at' => $this->responded_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
