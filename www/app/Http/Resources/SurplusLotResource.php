<?php

namespace App\Http\Resources;

use App\UserRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SurplusLotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $canSeeMinimum = $user && ($user->id === $this->producer_id || $user->hasRole(UserRole::Admin->value));

        return [
            'id' => $this->id,
            'producer' => new PublicUserResource($this->whenLoaded('producer')),
            'product' => new AgriculturalProductResource($this->whenLoaded('agriculturalProduct')),
            'quality_grade' => new QualityGradeResource($this->whenLoaded('qualityGrade')),
            'quantity' => $this->quantity,
            'unit' => $this->unit,
            'origin' => [
                'address' => $this->origin_address,
                'city' => $this->origin_city,
                'state' => $this->origin_state,
                'country' => $this->origin_country,
            ],
            'harvest_date' => $this->harvest_date?->toDateString(),
            'available_until' => $this->available_until?->toISOString(),
            'asking_price' => $this->asking_price,
            'minimum_price' => $this->when($canSeeMinimum, $this->minimum_price),
            'donation_eligible' => $this->donation_eligible,
            'accepted_logistics_modes' => $this->accepted_logistics_modes,
            'status' => $this->status->value,
            'reserved_at' => $this->reserved_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
