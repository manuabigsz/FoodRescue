<?php

namespace Tests\Feature\Api;

use App\Enums\OfferStatus;
use App\Enums\SurplusStatus;
use App\Models\AgriculturalProduct;
use App\Models\QualityGrade;
use App\Models\SurplusLot;
use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MarketplaceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_producer_creates_surplus_and_private_minimum_price_is_hidden_from_buyer(): void
    {
        $producer = User::factory()->withRole(UserRole::Producer)->create();
        $buyer = User::factory()->withRole(UserRole::Buyer)->create();
        $product = AgriculturalProduct::factory()->create();
        $quality = QualityGrade::factory()->create();

        Sanctum::actingAs($producer);
        $response = $this->postJson('/api/v1/surplus', $this->lotPayload($product->id, $quality->id))
            ->assertCreated()
            ->assertJsonPath('data.minimum_price', '18000.000000');

        $lotId = $response->json('data.id');
        Sanctum::actingAs($buyer);
        $this->getJson('/api/v1/surplus/'.$lotId)
            ->assertOk()
            ->assertJsonMissingPath('data.minimum_price');
    }

    public function test_buyer_offer_can_be_accepted_and_atomically_reserves_entire_lot(): void
    {
        $lot = SurplusLot::factory()->create();
        $buyer = User::factory()->withRole(UserRole::Buyer)->create();

        Sanctum::actingAs($buyer);
        $offerResponse = $this->postJson('/api/v1/surplus/'.$lot->id.'/offers', ['amount' => '19000.000000'])
            ->assertCreated();
        $offerId = $offerResponse->json('data.id');

        Sanctum::actingAs($lot->producer);
        $this->postEmptyJson('/api/v1/offers/'.$offerId.'/accept')
            ->assertCreated()
            ->assertJsonPath('data.product_amount', '19000.000000')
            ->assertJsonPath('data.protocol_fee', '380.000000')
            ->assertJsonPath('data.status', 'reserved');

        $this->assertSame(SurplusStatus::Reserved, $lot->fresh()->status);
        $this->assertDatabaseHas('offers', ['id' => $offerId, 'status' => OfferStatus::Accepted->value]);
        $this->assertDatabaseHas('trades', ['surplus_lot_id' => $lot->id, 'buyer_id' => $buyer->id]);
    }

    public function test_producer_delivery_is_not_an_available_logistics_mode(): void
    {
        $producer = User::factory()->withRole(UserRole::Producer)->create();
        $product = AgriculturalProduct::factory()->create();
        $quality = QualityGrade::factory()->create();

        Sanctum::actingAs($producer);
        $this->postJson('/api/v1/surplus', array_replace(
            $this->lotPayload($product->id, $quality->id),
            ['accepted_logistics_modes' => ['producer_delivery']],
        ))->assertUnprocessable();
    }

    public function test_buy_now_rejects_pending_offers_and_prevents_second_reservation(): void
    {
        $lot = SurplusLot::factory()->create();
        $firstBuyer = User::factory()->withRole(UserRole::Buyer)->create();
        $secondBuyer = User::factory()->withRole(UserRole::Buyer)->create();

        Sanctum::actingAs($firstBuyer);
        $this->postJson('/api/v1/surplus/'.$lot->id.'/offers', ['amount' => '17000'])->assertCreated();

        Sanctum::actingAs($secondBuyer);
        $this->postEmptyJson('/api/v1/surplus/'.$lot->id.'/buy-now')
            ->assertCreated()
            ->assertJsonPath('data.product_amount', '20000.000000');

        Sanctum::actingAs($firstBuyer);
        $this->postEmptyJson('/api/v1/surplus/'.$lot->id.'/buy-now')->assertConflict();
        $this->assertDatabaseCount('trades', 1);
        $this->assertDatabaseMissing('offers', ['surplus_lot_id' => $lot->id, 'status' => OfferStatus::Pending->value]);
    }

    /** @return array<string, mixed> */
    private function lotPayload(int $productId, int $qualityId): array
    {
        return [
            'agricultural_product_id' => $productId,
            'quality_grade_id' => $qualityId,
            'quantity' => '8000',
            'unit' => 'kg',
            'origin_address' => 'Zona Rural, km 12',
            'origin_city' => 'Campinas',
            'origin_state' => 'SP',
            'origin_country' => 'BR',
            'harvest_date' => now()->toDateString(),
            'available_until' => now()->addDay()->toISOString(),
            'asking_price' => '20000',
            'minimum_price' => '18000',
            'donation_eligible' => true,
            'accepted_logistics_modes' => ['buyer_pickup', 'third_party_carrier'],
        ];
    }
}
