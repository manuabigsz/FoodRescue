<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TradeResource;
use App\Models\SurplusLot;
use App\Services\Rescue\DonationService;
use App\UserRole;

class DonationController extends Controller
{
    public function accept(SurplusLot $surplusLot, DonationService $donations): TradeResource
    {
        $user = request()->user();
        abort_unless($user->hasRole(UserRole::Ngo->value), 403);

        return new TradeResource($donations->accept($user, $surplusLot));
    }
}
