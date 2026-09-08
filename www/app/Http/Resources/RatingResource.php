<?php

namespace App\Http\Resources;

use App\Models\Rating;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Rating */
class RatingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trade_id' => $this->trade_id,
            'reviewer_id' => $this->reviewer_id,
            'target_user_id' => $this->target_user_id,
            'rating' => $this->rating,
            'comment' => $this->comment,
            'reviewer' => new PublicUserResource($this->whenLoaded('reviewer')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
