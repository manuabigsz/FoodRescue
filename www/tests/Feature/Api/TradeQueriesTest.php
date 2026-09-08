<?php

namespace Tests\Feature\Api;

use App\Enums\TradeStatus;
use App\Models\PlatformSetting;
use App\Models\SurplusLot;
use App\Models\Trade;
use App\Models\User;
use App\Services\Logistics\TradeLogistics;
use App\Services\Marketplace\SurplusMarketplace;
use App\UserRole;
use App\UserStatus;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TradeQueriesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_listing_returns_only_the_operations_the_actor_takes_part_in(): void
    {
        [$trade, $buyer] = $this->reservedTrade();
        $stranger = User::factory()->withRole(UserRole::Buyer)->create();
        $otherTrade = $this->reservedTrade()[0];

        Sanctum::actingAs($buyer);
        $mine = $this->getJson('/api/v1/trades')->assertOk()->json('data');
        $this->assertSame([$trade->id], array_column($mine, 'id'));

        Sanctum::actingAs($trade->producer);
        $this->getJson('/api/v1/trades')->assertOk()->assertJsonPath('data.0.id', $trade->id);

        Sanctum::actingAs($stranger);
        $this->getJson('/api/v1/trades')->assertOk()->assertJsonCount(0, 'data');

        Sanctum::actingAs(User::factory()->withRole(UserRole::Admin)->create());
        $this->assertCount(2, $this->getJson('/api/v1/trades')->assertOk()->json('data'));
        $this->assertNotSame($trade->id, $otherTrade->id);
    }

    public function test_listing_exposes_pagination_and_accepts_status_and_donation_filters(): void
    {
        [$trade, $buyer] = $this->reservedTrade();

        Sanctum::actingAs($buyer);
        $this->getJson('/api/v1/trades?per_page=1&page=1&active=1')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $trade->id);

        $this->getJson('/api/v1/trades?status='.TradeStatus::Completed->value)
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/v1/trades?is_donation=1')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/v1/trades?unknown=1')->assertUnprocessable();
    }

    public function test_show_returns_the_lot_and_is_denied_to_outsiders(): void
    {
        [$trade, $buyer] = $this->reservedTrade();

        Sanctum::actingAs($buyer);
        $this->getJson('/api/v1/trades/'.$trade->id)
            ->assertOk()
            ->assertJsonPath('data.id', $trade->id)
            ->assertJsonPath('data.surplus_lot.id', $trade->surplus_lot_id)
            ->assertJsonPath('data.surplus_lot.origin.city', 'Campinas');

        Sanctum::actingAs(User::factory()->withRole(UserRole::Buyer)->create());
        $this->getJson('/api/v1/trades/'.$trade->id)->assertForbidden();
    }

    public function test_carrier_only_sees_the_operation_after_having_its_quotation_selected(): void
    {
        PlatformSetting::query()->updateOrCreate(['key' => PlatformSetting::SHIPPING_QUOTATION_TIMEOUT], ['value' => '240']);
        PlatformSetting::query()->updateOrCreate(['key' => PlatformSetting::PAYMENT_TIMEOUT], ['value' => '15']);

        [$trade, $buyer] = $this->reservedTrade();
        $carrier = User::factory()->withRole(UserRole::Carrier)->create();
        $rival = User::factory()->withRole(UserRole::Carrier)->create();
        $logistics = app(TradeLogistics::class);

        $shipping = $logistics->createShippingRequest($buyer, $trade, $this->destination());
        $offer = $logistics->createOffer($carrier, $shipping, [
            'amount' => '480.000000',
            'pickup_at' => now()->addHour()->toISOString(),
            'estimated_delivery_at' => now()->addHours(5)->toISOString(),
        ]);

        Sanctum::actingAs($carrier);
        $this->getJson('/api/v1/trades/'.$trade->id)->assertForbidden();

        $logistics->selectOffer($buyer, $trade->fresh(), $offer);

        Sanctum::actingAs($carrier);
        $this->getJson('/api/v1/trades/'.$trade->id)->assertOk()->assertJsonPath('data.id', $trade->id);
        $this->getJson('/api/v1/trades')->assertOk()->assertJsonPath('data.0.id', $trade->id);

        Sanctum::actingAs($rival);
        $this->getJson('/api/v1/trades/'.$trade->id)->assertForbidden();
        $this->getJson('/api/v1/trades')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_blocked_actor_cannot_read_its_own_operations(): void
    {
        [$trade, $buyer] = $this->reservedTrade();
        $buyer->status = UserStatus::Blocked;
        $buyer->save();

        Sanctum::actingAs($buyer->fresh());
        $this->getJson('/api/v1/trades/'.$trade->id)->assertForbidden();
    }

    public function test_listing_requires_authentication(): void
    {
        $this->getJson('/api/v1/trades')->assertUnauthorized();
    }

    /** @return array{0: Trade, 1: User} */
    private function reservedTrade(): array
    {
        $lot = SurplusLot::factory()->create();
        $buyer = User::factory()->withRole(UserRole::Buyer)->create();

        return [app(SurplusMarketplace::class)->buyNow($buyer, $lot), $buyer];
    }

    /** @return array<string, string> */
    private function destination(): array
    {
        return [
            'destination_address' => 'Av. Central, 100',
            'destination_city' => 'São Paulo',
            'destination_state' => 'SP',
            'destination_country' => 'BR',
        ];
    }
}
