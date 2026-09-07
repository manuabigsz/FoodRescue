<?php

namespace App\Policies;

use App\Models\User;
use App\UserRole;
use App\UserStatus;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->createAdmin($user);
    }

    public function view(User $user, User $target): bool
    {
        return $this->createAdmin($user);
    }

    public function createAdmin(User $user): bool
    {
        return $user->status === UserStatus::Active && $user->hasRole(UserRole::Admin->value);
    }

    public function updateStatus(User $user, User $target): bool
    {
        return $this->createAdmin($user);
    }
}
