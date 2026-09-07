<?php

namespace Tests\Feature\Api;

use App\Enums\SurplusStatus;
use App\Enums\TradeStatus;
use App\Models\SurplusLot;
use App\Models\User;
use App\Services\Logistics\TradeDelivery;
use App\Services\Logistics\TradeLogistics;
use App\Services\Rescue\DonationService;
use App\UserRole;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DonationFlowTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_ngo_can_accept_eligible_surplus_as_zero_value_donation(): void
    {
        $lot = SurplusLot::factory()->create(['donation_eligible' => true]);
        $ngo = User::factory()->withRole(UserRole::Ngo)->create();

        Sanctum::actingAs($ngo);
        $response = $this->postEmptyJson('/api/v1/surplus/'.$lot->id.'/donations/accept')
            ->assertCreated()
            ->assertJsonPath('data.is_donation', true)
            ->assertJsonPath('data.recipient_type', 'ngo')
            ->assertJsonPath('data.product_amount', '0.000000')
            ->assertJsonPath('data.protocol_fee', '0.000000')
            ->assertJsonPath('data.status', TradeStatus::Reserved->value);

        $this->assertSame(SurplusStatus::Reserved, $lot->fresh()->status);
        $this->assertDatabaseHas('trades', [
            'id' => $response->json('data.id'),
            'buyer_id' => $ngo->id,
            'is_donation' => true,
        ]);
    }

    public function test_ngo_managed_transport_skips_financial_escrow_and_enters_delivery_flow(): void
    {
        $producer = User::factory()->withRole(UserRole::Producer)->create();
        $lot = SurplusLot::factory()->create(['producer_id' => $producer->id, 'donation_eligible' => true]);
        $ngo = User::factory()->withRole(UserRole::Ngo)->create();
        $trade = app(DonationService::class)->accept($ngo, $lot);

        app(TradeLogistics::class)->recipientManaged($ngo, $trade, [
            'destination_address' => 'Rua Social, 10',
            'destination_city' => 'Campinas',
            'destination_state' => 'SP',
            'destination_country' => 'BR',
        ]);

        $trade->refresh();
        $this->assertSame(TradeStatus::Funded, $trade->status);
        $this->assertSame('0.000000', $trade->shipping_amount);
        $this->assertNull($trade->payment_expires_at);
        $this->assertNull($trade->blockchainAccount);

        app(TradeDelivery::class)->markReadyForPickup($producer, $trade);
        app(TradeDelivery::class)->confirmPickup($ngo, $trade->fresh());
        app(TradeDelivery::class)->markDelivered($ngo, $trade->fresh());

        $this->assertSame(TradeStatus::Delivered, $trade->fresh()->status);
    }

    public function test_non_ngo_cannot_accept_donation(): void
    {
        $lot = SurplusLot::factory()->create(['donation_eligible' => true]);
        $buyer = User::factory()->withRole(UserRole::Buyer)->create();

        Sanctum::actingAs($buyer);
        $this->postEmptyJson('/api/v1/surplus/'.$lot->id.'/donations/accept')->assertForbidden();
    }
}
