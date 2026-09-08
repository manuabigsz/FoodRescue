<?php

namespace Tests\Feature\Api;

use App\Enums\ShippingOfferStatus;
use App\Enums\ShippingRequestStatus;
use App\Enums\SurplusStatus;
use App\Enums\TradeStatus;
use App\Models\PlatformSetting;
use App\Models\SurplusLot;
use App\Models\Trade;
use App\Models\User;
use App\Services\Logistics\TradeLogistics;
use App\Services\Marketplace\SurplusMarketplace;
use App\UserRole;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShippingLogisticsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_buyer_creates_shipping_request_carrier_quotes_and_buyer_selects_offer(): void
    {
        PlatformSetting::query()->updateOrCreate(['key' => PlatformSetting::SHIPPING_QUOTATION_TIMEOUT], ['value' => '240']);
        PlatformSetting::query()->updateOrCreate(['key' => PlatformSetting::PAYMENT_TIMEOUT], ['value' => '15']);

        [$trade, $buyer] = $this->reservedTrade();
        $carrier = User::factory()->withRole(UserRole::Carrier)->create();

        Sanctum::actingAs($buyer);
        $shipping = $this->postJson('/api/v1/trades/'.$trade->id.'/shipping', $this->destination())
            ->assertCreated()
            ->assertJsonPath('data.status', ShippingRequestStatus::Quoting->value);

        $shippingId = $shipping->json('data.id');
        $this->assertSame(TradeStatus::ShippingQuotation, $trade->fresh()->status);

        Sanctum::actingAs($carrier);
        $offer = $this->postJson('/api/v1/shipping-requests/'.$shippingId.'/offers', [
            'amount' => '480.000000',
            'pickup_at' => now()->addHour()->toISOString(),
            'estimated_delivery_at' => now()->addHours(5)->toISOString(),
        ])->assertCreated()->assertJsonPath('data.status', ShippingOfferStatus::Pending->value);

        Sanctum::actingAs($buyer);
        $this->postEmptyJson('/api/v1/trades/'.$trade->id.'/shipping-offers/'.$offer->json('data.id').'/select')
            ->assertOk()
            ->assertJsonPath('data.shipping_amount', '480.000000')
            ->assertJsonPath('data.status', TradeStatus::WaitingPayment->value)
            ->assertJsonPath('data.buyer_total', '20480.000000');

        $this->assertDatabaseHas('shipping_requests', [
            'id' => $shippingId,
            'status' => ShippingRequestStatus::CarrierSelected->value,
            'selected_shipping_offer_id' => $offer->json('data.id'),
        ]);
        $this->assertNotNull($trade->fresh()->payment_expires_at);
    }

    public function test_buyer_can_choose_buyer_managed_transport_immediately(): void
    {
        PlatformSetting::query()->updateOrCreate(['key' => PlatformSetting::PAYMENT_TIMEOUT], ['value' => '15']);
        [$trade, $buyer] = $this->reservedTrade();

        Sanctum::actingAs($buyer);
        $this->postJson('/api/v1/trades/'.$trade->id.'/buyer-managed', $this->destination())
            ->assertOk()
            ->assertJsonPath('data.shipping_amount', '0.000000')
            ->assertJsonPath('data.status', TradeStatus::WaitingPayment->value)
            ->assertJsonPath('data.shipping_request.status', ShippingRequestStatus::BuyerManaged->value);
    }

    public function test_expired_quotation_falls_back_to_buyer_managed_and_unfunded_trade_reopens_lot(): void
    {
        PlatformSetting::query()->updateOrCreate(['key' => PlatformSetting::SHIPPING_QUOTATION_TIMEOUT], ['value' => '1']);
        PlatformSetting::query()->updateOrCreate(['key' => PlatformSetting::PAYMENT_TIMEOUT], ['value' => '1']);
        [$trade, $buyer] = $this->reservedTrade();

        Sanctum::actingAs($buyer);
        $shippingId = $this->postJson('/api/v1/trades/'.$trade->id.'/shipping', $this->destination())
            ->assertCreated()->json('data.id');

        $this->travel(2)->minutes();
        app(TradeLogistics::class)->expireQuotations();

        $this->assertDatabaseHas('shipping_requests', ['id' => $shippingId, 'status' => ShippingRequestStatus::BuyerManaged->value]);
        $this->assertSame(TradeStatus::WaitingPayment, $trade->fresh()->status);

        $this->travel(2)->minutes();
        app(TradeLogistics::class)->expireUnfundedTrades();

        $this->assertSame(TradeStatus::Expired, $trade->fresh()->status);
        $this->assertSame(SurplusStatus::Open, $trade->surplusLot->fresh()->status);
        $this->assertDatabaseHas('shipping_requests', ['id' => $shippingId, 'status' => ShippingRequestStatus::Expired->value]);
    }

    /** @return array{0: Trade, 1: User} */
    public function test_carrier_sees_and_revises_its_own_pending_quotation(): void
    {
        PlatformSetting::query()->updateOrCreate(['key' => PlatformSetting::SHIPPING_QUOTATION_TIMEOUT], ['value' => '240']);
        [$trade, $buyer] = $this->reservedTrade();
        $carrier = User::factory()->withRole(UserRole::Carrier)->create();
        $rival = User::factory()->withRole(UserRole::Carrier)->create();

        Sanctum::actingAs($buyer);
        $shippingId = $this->postJson('/api/v1/trades/'.$trade->id.'/shipping', $this->destination())->assertCreated()->json('data.id');

        Sanctum::actingAs($carrier);
        $offerId = $this->postJson('/api/v1/shipping-requests/'.$shippingId.'/offers', $this->quotation('480.000000'))
            ->assertCreated()->json('data.id');

        $listed = $this->getJson('/api/v1/shipping-requests')->assertOk()->json('data.0.offers');
        $this->assertCount(1, $listed);
        $this->assertSame($offerId, $listed[0]['id']);

        Sanctum::actingAs($rival);
        $this->assertCount(0, $this->getJson('/api/v1/shipping-requests')->assertOk()->json('data.0.offers'));

        Sanctum::actingAs($carrier);
        $this->patchJson('/api/v1/shipping-offers/'.$offerId, $this->quotation('390.000000'))
            ->assertOk()
            ->assertJsonPath('data.id', $offerId)
            ->assertJsonPath('data.amount', '390.000000');
        $this->assertDatabaseCount('shipping_offers', 1);

        $this->postJson('/api/v1/shipping-requests/'.$shippingId.'/offers', $this->quotation('300.000000'))
            ->assertStatus(409);
    }

    public function test_quotation_cannot_be_revised_by_another_carrier_or_after_being_selected(): void
    {
        PlatformSetting::query()->updateOrCreate(['key' => PlatformSetting::SHIPPING_QUOTATION_TIMEOUT], ['value' => '240']);
        PlatformSetting::query()->updateOrCreate(['key' => PlatformSetting::PAYMENT_TIMEOUT], ['value' => '15']);
        [$trade, $buyer] = $this->reservedTrade();
        $carrier = User::factory()->withRole(UserRole::Carrier)->create();
        $rival = User::factory()->withRole(UserRole::Carrier)->create();

        Sanctum::actingAs($buyer);
        $shippingId = $this->postJson('/api/v1/trades/'.$trade->id.'/shipping', $this->destination())->assertCreated()->json('data.id');

        Sanctum::actingAs($carrier);
        $offerId = $this->postJson('/api/v1/shipping-requests/'.$shippingId.'/offers', $this->quotation('480.000000'))->assertCreated()->json('data.id');

        Sanctum::actingAs($rival);
        $this->patchJson('/api/v1/shipping-offers/'.$offerId, $this->quotation('100.000000'))->assertForbidden();

        Sanctum::actingAs($buyer);
        $this->postEmptyJson('/api/v1/trades/'.$trade->id.'/shipping-offers/'.$offerId.'/select')->assertOk();

        Sanctum::actingAs($carrier);
        $this->patchJson('/api/v1/shipping-offers/'.$offerId, $this->quotation('100.000000'))->assertStatus(409);
        $this->assertDatabaseHas('shipping_offers', ['id' => $offerId, 'amount' => '480.000000']);
    }

    /** @return array<string, string> */
    private function quotation(string $amount): array
    {
        return [
            'amount' => $amount,
            'pickup_at' => now()->addHour()->toISOString(),
            'estimated_delivery_at' => now()->addHours(5)->toISOString(),
        ];
    }

    private function reservedTrade(): array
    {
        $lot = SurplusLot::factory()->create();
        $buyer = User::factory()->withRole(UserRole::Buyer)->create();
        $trade = app(SurplusMarketplace::class)->buyNow($buyer, $lot);

        return [$trade, $buyer];
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
