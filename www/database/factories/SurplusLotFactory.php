<?php

namespace Database\Factories;

use App\Enums\LogisticsMode;
use App\Enums\SurplusStatus;
use App\Enums\SurplusUnit;
use App\Models\AgriculturalProduct;
use App\Models\QualityGrade;
use App\Models\SurplusLot;
use App\Models\User;
use App\UserRole;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SurplusLot> */
class SurplusLotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'producer_id' => User::factory()->withRole(UserRole::Producer),
            'agricultural_product_id' => AgriculturalProduct::factory(),
            'quality_grade_id' => QualityGrade::factory(),
            'quantity' => '8000.000',
            'unit' => SurplusUnit::Kilogram->value,
            'origin_address' => 'Zona Rural, km 12',
            'origin_city' => 'Campinas',
            'origin_state' => 'SP',
            'origin_country' => 'BR',
            'harvest_date' => now()->toDateString(),
            'available_until' => now()->addDay(),
            'asking_price' => '20000.000000',
            'minimum_price' => '18000.000000',
            'donation_eligible' => true,
            'accepted_logistics_modes' => [LogisticsMode::BuyerPickup->value, LogisticsMode::ThirdPartyCarrier->value],
            'status' => SurplusStatus::Open,
        ];
    }
}
