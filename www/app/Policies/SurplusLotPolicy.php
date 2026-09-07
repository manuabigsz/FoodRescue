<?php

namespace App\Policies;

use App\Enums\SurplusStatus;
use App\Models\SurplusLot;
use App\Models\User;
use App\UserRole;
use App\UserStatus;

class SurplusLotPolicy
{
    public function create(User $user): bool
    {
        return $user->status === UserStatus::Active && $user->hasRole(UserRole::Producer->value);
    }

    public function update(User $user, SurplusLot $lot): bool
    {
        return $this->create($user) && $lot->producer_id === $user->id && $lot->status === SurplusStatus::Open;
    }

    public function cancel(User $user, SurplusLot $lot): bool
    {
        return $this->update($user, $lot);
    }
}
