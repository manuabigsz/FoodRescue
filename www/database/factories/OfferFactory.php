<?php

namespace Database\Factories;

use App\Enums\OfferStatus;
use App\Models\Offer;
use App\Models\SurplusLot;
use App\Models\User;
use App\UserRole;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Offer> */
class OfferFactory extends Factory
{
    public function definition(): array
    {
        return [
            'surplus_lot_id' => SurplusLot::factory(),
            'buyer_id' => User::factory()->withRole(UserRole::Buyer),
            'amount' => '19000.000000',
            'status' => OfferStatus::Pending,
            'expires_at' => now()->addHours(2),
        ];
    }
}
