<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'solana_wallet_address' => $this->solana_wallet_address,
            'solana_wallet_verified' => $this->solana_wallet_verified_at !== null,
            'solana_wallet_verified_at' => $this->solana_wallet_verified_at?->toISOString(),
            'status' => $this->status->value,
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')->values()),
            'profile' => $this->actorProfile(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function actorProfile(): ?array
    {
        foreach (['producerProfile', 'buyerProfile', 'carrierProfile', 'ngoProfile'] as $relation) {
            if (! $this->relationLoaded($relation) || ! $this->{$relation}) {
                continue;
            }

            return collect($this->{$relation}->toArray())
                ->except(['id', 'user_id', 'created_at', 'updated_at'])
                ->all();
        }

        return null;
    }
}
