<?php

namespace Tests\Feature\Api;

use App\Enums\OfferStatus;
use App\Enums\ShippingOfferStatus;
use App\Enums\ShippingRequestStatus;
use App\Enums\SurplusStatus;
use App\Enums\TradeStatus;
use App\Models\Offer;
use App\Models\SurplusLot;
use App\Models\User;
use App\Services\Logistics\TradeLogistics;
use App\Services\Marketplace\SurplusMarketplace;
use App\UserRole;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StateMachineValidationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const DESTINATION = ['destination_address' => 'Warehouse', 'destination_city' => 'Campinas', 'destination_state' => 'SP', 'destination_country' => 'BR'];

    public static function tradeActions(): array
    {
        $cases = [];
        foreach (TradeStatus::cases() as $status) {
            foreach (['shipping', 'buyer-managed', 'cancel'] as $action) {
                $cases[$status->value.':'.$action] = [$status, $action];
            }
        }

        return $cases;
    }

    #[DataProvider('tradeActions')]
    public function test_logistics_and_offchain_cancellation_state_matrix(TradeStatus $status, string $action): void
    {
        $buyer = User::factory()->withRole(UserRole::Buyer)->create();
        $lot = SurplusLot::factory()->create();
        $trade = app(SurplusMarketplace::class)->buyNow($buyer, $lot);
        $trade->update(['status' => $status]);
        $allowed = match ($action) {
            'shipping' => $status === TradeStatus::Reserved,
            'buyer-managed' => in_array($status, [TradeStatus::Reserved, TradeStatus::ShippingQuotation], true),
            'cancel' => in_array($status, [TradeStatus::Reserved, TradeStatus::ShippingQuotation, TradeStatus::CarrierSelected, TradeStatus::BuyerManaged, TradeStatus::WaitingPayment], true),
        };
        Sanctum::actingAs($buyer);
        $this->postJson('/api/v1/trades/'.$trade->id.'/'.$action, $action === 'cancel' ? ['reason' => 'State validation'] : self::DESTINATION)
            ->assertStatus($allowed ? ($action === 'shipping' ? 201 : 200) : 409);
        $expected = $allowed ? match ($action) {
            'shipping' => TradeStatus::ShippingQuotation,
            'buyer-managed' => TradeStatus::WaitingPayment,
            'cancel' => TradeStatus::Cancelled,
        } : $status;
        $this->assertSame($expected, $trade->fresh()->status);
    }

    public static function shippingStates(): array
    {
        $cases = [];
        foreach (ShippingRequestStatus::cases() as $request) {
            foreach (ShippingOfferStatus::cases() as $offer) {
                $cases[$request->value.':'.$offer->value] = [$request, $offer];
            }
        }

        return $cases;
    }

    #[DataProvider('shippingStates')]
    public function test_shipping_offer_selection_state_matrix(ShippingRequestStatus $requestState, ShippingOfferStatus $offerState): void
    {
        [$buyer, $carrier, $trade, $request] = $this->quotation();
        $offer = app(TradeLogistics::class)->createOffer($carrier, $request, $this->offerData());
        $request->update(['status' => $requestState]);
        $offer->update(['status' => $offerState]);
        $allowed = $requestState === ShippingRequestStatus::Quoting && $offerState === ShippingOfferStatus::Pending;
        Sanctum::actingAs($buyer);
        $this->postEmptyJson('/api/v1/trades/'.$trade->id.'/shipping-offers/'.$offer->id.'/select')->assertStatus($allowed ? 200 : 409);
        $this->assertSame($allowed ? ShippingRequestStatus::CarrierSelected : $requestState, $request->fresh()->status);
        $this->assertSame($allowed ? ShippingOfferStatus::Selected : $offerState, $offer->fresh()->status);
    }

    public function test_carrier_can_replace_an_expired_offer_while_quotation_is_open(): void
    {
        [, $carrier, , $request] = $this->quotation();
        $old = app(TradeLogistics::class)->createOffer($carrier, $request, $this->offerData());
        $old->update(['expires_at' => now()->subSecond()]);
        Sanctum::actingAs($carrier);
        $this->postJson('/api/v1/shipping-requests/'.$request->id.'/offers', $this->offerData())->assertCreated();
        $this->assertSame(ShippingOfferStatus::Expired, $old->fresh()->status);
        $this->assertSame(1, $request->offers()->where('status', ShippingOfferStatus::Pending)->count());
    }

    public static function lots(): array
    {
        return array_map(fn ($state) => [$state], SurplusStatus::cases());
    }

    #[DataProvider('lots')]
    public function test_donation_acceptance_state_matrix(SurplusStatus $status): void
    {
        $lot = SurplusLot::factory()->create(['status' => $status, 'donation_eligible' => true]);
        Sanctum::actingAs(User::factory()->withRole(UserRole::Ngo)->create());
        $this->postEmptyJson('/api/v1/surplus/'.$lot->id.'/donations/accept')->assertStatus($status === SurplusStatus::Open ? 201 : 409);
        $this->assertSame($status === SurplusStatus::Open ? SurplusStatus::Reserved : $status, $lot->fresh()->status);
    }

    public static function offers(): array
    {
        return array_map(fn ($state) => [$state], OfferStatus::cases());
    }

    #[DataProvider('offers')]
    public function test_offer_rejection_state_matrix(OfferStatus $status): void
    {
        $lot = SurplusLot::factory()->create();
        $offer = Offer::factory()->create(['surplus_lot_id' => $lot->id, 'status' => $status]);
        Sanctum::actingAs($lot->producer);
        $this->postEmptyJson('/api/v1/offers/'.$offer->id.'/reject')->assertStatus($status === OfferStatus::Pending ? 200 : 409);
        $this->assertSame($status === OfferStatus::Pending ? OfferStatus::Rejected : $status, $offer->fresh()->status);
    }

    private function quotation(): array
    {
        $buyer = User::factory()->withRole(UserRole::Buyer)->create();
        $carrier = User::factory()->withRole(UserRole::Carrier)->create();
        $trade = app(SurplusMarketplace::class)->buyNow($buyer, SurplusLot::factory()->create());
        $request = app(TradeLogistics::class)->createShippingRequest($buyer, $trade, self::DESTINATION);

        return [$buyer, $carrier, $trade, $request];
    }

    private function offerData(): array
    {
        return ['amount' => '10.000001', 'pickup_at' => now()->addHour()->toISOString(), 'estimated_delivery_at' => now()->addHours(2)->toISOString()];
    }
}
