<?php

namespace App\Policies;

use App\Models\Offer;
use App\Models\SurplusLot;
use App\Models\User;
use App\UserRole;
use App\UserStatus;

class OfferPolicy
{
    public function create(User $user, SurplusLot $lot): bool
    {
        return $user->status === UserStatus::Active && $user->hasRole(UserRole::Buyer->value) && $lot->producer_id !== $user->id;
    }

    /**
     * As propostas de um lote são visíveis para o produtor dono e para o administrador.
     */
    public function viewAny(User $user, SurplusLot $lot): bool
    {
        if ($user->status !== UserStatus::Active) {
            return false;
        }

        return $lot->producer_id === $user->id || $user->hasRole(UserRole::Admin->value);
    }

    public function respond(User $user, Offer $offer): bool
    {
        return $user->status === UserStatus::Active
            && $user->hasRole(UserRole::Producer->value)
            && $offer->surplusLot()->where('producer_id', $user->id)->exists();
    }
}
