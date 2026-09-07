<?php

namespace Tests\Feature\Api;

use App\Enums\SurplusStatus;
use App\Enums\TradeStatus;
use App\Models\AgriculturalProduct;
use App\Models\SurplusLot;
use App\Models\Trade;
use App\Models\User;
use App\UserRole;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionsSeeder::class);
    }

    public function test_producer_dashboard_groups_quantities_by_unit_and_counts_only_completed_revenue(): void
    {
        $producer = User::factory()->create();
        $producer->assignRole(UserRole::Producer->value);
        $buyer = User::factory()->create();
        $buyer->assignRole(UserRole::Buyer->value);
        $product = AgriculturalProduct::factory()->create();

        $kg = SurplusLot::factory()->create([
            'producer_id' => $producer->id,
            'agricultural_product_id' => $product->id,
            'quantity' => 500,
            'unit' => 'kg',
            'status' => SurplusStatus::Sold,
        ]);
        $boxes = SurplusLot::factory()->create([
            'producer_id' => $producer->id,
            'agricultural_product_id' => $product->id,
            'quantity' => 20,
            'unit' => 'box',
            'status' => SurplusStatus::Sold,
        ]);

        Trade::query()->create([
            'surplus_lot_id' => $kg->id,
            'producer_id' => $producer->id,
            'buyer_id' => $buyer->id,
            'product_amount' => 1000,
            'shipping_amount' => 100,
            'protocol_fee' => 20,
            'status' => TradeStatus::Completed,
            'is_donation' => false,
            'completed_at' => now(),
        ]);
        Trade::query()->create([
            'surplus_lot_id' => $boxes->id,
            'producer_id' => $producer->id,
            'buyer_id' => $buyer->id,
            'product_amount' => 400,
            'shipping_amount' => 0,
            'protocol_fee' => 8,
            'status' => TradeStatus::Completed,
            'is_donation' => false,
            'completed_at' => now(),
        ]);

        Sanctum::actingAs($producer);
        $this->getJson('/api/v1/dashboard/producer')
            ->assertOk()
            ->assertJsonPath('data.summary.commercial_operations', 2)
            ->assertJsonPath('data.summary.recovered_revenue', '1372.000000')
            ->assertJsonPath('data.quantities.sold.kg', '500.000')
            ->assertJsonPath('data.quantities.sold.box', '20.000');
    }

    public function test_actor_cannot_open_dashboard_for_another_role(): void
    {
        $buyer = User::factory()->create();
        $buyer->assignRole(UserRole::Buyer->value);

        Sanctum::actingAs($buyer);
        $this->getJson('/api/v1/dashboard/producer')
            ->assertForbidden();
    }

    public function test_admin_impact_excludes_donations_from_protocol_fee_and_product_revenue(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::Admin->value);
        $producer = User::factory()->create();
        $producer->assignRole(UserRole::Producer->value);
        $buyer = User::factory()->create();
        $buyer->assignRole(UserRole::Buyer->value);
        $ngo = User::factory()->create();
        $ngo->assignRole(UserRole::Ngo->value);
        $product = AgriculturalProduct::factory()->create();

        $saleLot = SurplusLot::factory()->create([
            'producer_id' => $producer->id,
            'agricultural_product_id' => $product->id,
            'quantity' => 100,
            'unit' => 'kg',
            'status' => SurplusStatus::Sold,
        ]);
        $donationLot = SurplusLot::factory()->create([
            'producer_id' => $producer->id,
            'agricultural_product_id' => $product->id,
            'quantity' => 50,
            'unit' => 'kg',
            'status' => SurplusStatus::Donated,
        ]);

        Trade::query()->create([
            'surplus_lot_id' => $saleLot->id,
            'producer_id' => $producer->id,
            'buyer_id' => $buyer->id,
            'product_amount' => 1000,
            'shipping_amount' => 100,
            'protocol_fee' => 20,
            'status' => TradeStatus::Completed,
            'is_donation' => false,
            'completed_at' => now(),
        ]);
        Trade::query()->create([
            'surplus_lot_id' => $donationLot->id,
            'producer_id' => $producer->id,
            'buyer_id' => $ngo->id,
            'product_amount' => 0,
            'shipping_amount' => 50,
            'protocol_fee' => 0,
            'status' => TradeStatus::Completed,
            'is_donation' => true,
            'completed_at' => now(),
        ]);

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/impact')
            ->assertOk()
            ->assertJsonPath('data.quantities.total_destined.kg', '150.000')
            ->assertJsonPath('data.quantities.sold.kg', '100.000')
            ->assertJsonPath('data.quantities.donated.kg', '50.000')
            ->assertJsonPath('data.financial.producer_revenue_recovered', '980.000000')
            ->assertJsonPath('data.financial.protocol_fees', '20.000000')
            ->assertJsonPath('data.financial.freight_paid', '150.000000')
            ->assertJsonPath('data.operations.commercial', 1)
            ->assertJsonPath('data.operations.donations', 1);
    }
}
