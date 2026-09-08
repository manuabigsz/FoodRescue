<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Identificação mínima de uma contraparte.
 *
 * Usada onde um ator aparece embutido em outro recurso — produtor de um lote,
 * comprador de uma proposta, transportadora de uma cotação. Dados de contato
 * ficam de fora: só o próprio usuário e o administrador os obtêm, via UserResource.
 *
 * @mixin User
 */
class PublicUserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'solana_wallet_address' => $this->solana_wallet_address,
            'solana_wallet_verified' => $this->solana_wallet_verified_at !== null,
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')->values()),
        ];
    }
}
