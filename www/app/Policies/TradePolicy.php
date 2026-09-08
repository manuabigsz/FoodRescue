<?php

namespace App\Policies;

use App\Models\Trade;
use App\Models\User;
use App\UserRole;
use App\UserStatus;
use Illuminate\Database\Eloquent\Builder;

class TradePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->status === UserStatus::Active;
    }

    /**
     * Produtor e destinatário sempre enxergam a operação. A transportadora só
     * enxerga depois de ter a própria cotação selecionada, e o administrador
     * enxerga qualquer operação.
     */
    public function view(User $user, Trade $trade): bool
    {
        if ($user->status !== UserStatus::Active) {
            return false;
        }

        if ($trade->producer_id === $user->id || $trade->buyer_id === $user->id) {
            return true;
        }

        if ($user->hasRole(UserRole::Admin->value)) {
            return true;
        }

        return $user->hasRole(UserRole::Carrier->value) && $this->isSelectedCarrier($user, $trade);
    }

    private function isSelectedCarrier(User $user, Trade $trade): bool
    {
        return $trade->shippingRequest()
            ->whereHas('selectedOffer', fn (Builder $offer) => $offer->where('carrier_id', $user->id))
            ->exists();
    }
}
