<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TradeResource;
use App\Models\Offer;
use App\Models\SurplusLot;
use App\Services\Marketplace\SurplusMarketplace;
use App\UserRole;
use Illuminate\Support\Facades\Gate;

class TradeController extends Controller
{
    public function buyNow(SurplusLot $surplusLot, SurplusMarketplace $marketplace): TradeResource
    {
        $user = request()->user();
        Gate::authorize('create', [Offer::class, $surplusLot]);
        abort_unless($user->hasRole(UserRole::Buyer->value), 403);

        return new TradeResource($marketplace->buyNow($user, $surplusLot));
    }
}
