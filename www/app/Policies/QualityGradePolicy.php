<?php

namespace App\Policies;

use App\Models\QualityGrade;
use App\Models\User;
use App\UserRole;
use App\UserStatus;

class QualityGradePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->manage($user);
    }

    public function create(User $user): bool
    {
        return $this->manage($user);
    }

    public function update(User $user, QualityGrade $qualityGrade): bool
    {
        return $this->manage($user);
    }

    private function manage(User $user): bool
    {
        return $user->status === UserStatus::Active && $user->hasRole(UserRole::Admin->value);
    }
}
