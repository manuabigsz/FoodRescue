<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShippingOfferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shipping_request_id' => $this->shipping_request_id,
            'carrier_id' => $this->carrier_id,
            'carrier' => new UserResource($this->whenLoaded('carrier')),
            'amount' => $this->amount,
            'pickup_at' => $this->pickup_at?->toISOString(),
            'estimated_delivery_at' => $this->estimated_delivery_at?->toISOString(),
            'expires_at' => $this->expires_at?->toISOString(),
            'status' => $this->status->value,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
