<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShippingRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trade_id' => $this->trade_id,
            'origin' => [
                'address' => $this->origin_address,
                'city' => $this->origin_city,
                'state' => $this->origin_state,
                'country' => $this->origin_country,
            ],
            'destination' => [
                'address' => $this->destination_address,
                'city' => $this->destination_city,
                'state' => $this->destination_state,
                'country' => $this->destination_country,
            ],
            'quantity' => $this->quantity,
            'unit' => $this->unit,
            'quotation_expires_at' => $this->quotation_expires_at?->toISOString(),
            'status' => $this->status->value,
            'selected_shipping_offer_id' => $this->selected_shipping_offer_id,
            'selected_offer' => new ShippingOfferResource($this->whenLoaded('selectedOffer')),
            'offers' => ShippingOfferResource::collection($this->whenLoaded('offers')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
