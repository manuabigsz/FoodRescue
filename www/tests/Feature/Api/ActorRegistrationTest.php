<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Support\Base58;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ActorRegistrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_producer_registration_creates_producer_profile(): void
    {
        $this->seed(RolesSeeder::class);

        $this->postJson('/api/v1/auth/register', $this->payload('producer', [
            'producer_type' => 'cooperative',
            'organization_name' => 'Cooperativa FoodRescue',
            'farm_name' => 'Unidade Norte',
            'document_number' => 'PROD-001',
        ]))->assertCreated()
            ->assertJsonPath('data.roles', ['producer'])
            ->assertJsonPath('data.profile.producer_type', 'cooperative')
            ->assertJsonPath('data.profile.organization_name', 'Cooperativa FoodRescue');

        $user = User::whereEmail('actor@example.com')->firstOrFail();
        $this->assertDatabaseHas('producer_profiles', [
            'user_id' => $user->id,
            'farm_name' => 'Unidade Norte',
            'document_number' => 'PROD-001',
        ]);
        $this->assertDatabaseCount('buyer_profiles', 0);
        $this->assertDatabaseCount('carrier_profiles', 0);
        $this->assertDatabaseCount('ngo_profiles', 0);
    }

    public function test_buyer_registration_creates_buyer_profile(): void
    {
        $this->seed(RolesSeeder::class);

        $this->postJson('/api/v1/auth/register', $this->payload('buyer', [
            'buyer_type' => 'company',
            'organization_name' => 'Mercado Central',
            'document_number' => 'BUY-001',
        ]))->assertCreated()
            ->assertJsonPath('data.profile.buyer_type', 'company')
            ->assertJsonPath('data.profile.organization_name', 'Mercado Central');

        $user = User::whereEmail('actor@example.com')->firstOrFail();
        $this->assertDatabaseHas('buyer_profiles', ['user_id' => $user->id, 'document_number' => 'BUY-001']);
    }

    public function test_carrier_registration_creates_operational_profile(): void
    {
        $this->seed(RolesSeeder::class);

        $this->postJson('/api/v1/auth/register', $this->payload('carrier', [
            'company_name' => 'FoodRescue Transportes',
            'document_number' => 'CAR-001',
            'contact_name' => 'João Logística',
            'service_regions' => ['Campinas', 'São Paulo'],
            'vehicle_types' => ['truck', 'refrigerated_truck'],
            'max_capacity_kg' => 18000,
        ]))->assertCreated()
            ->assertJsonPath('data.profile.company_name', 'FoodRescue Transportes')
            ->assertJsonPath('data.profile.service_regions.0', 'Campinas')
            ->assertJsonPath('data.profile.max_capacity_kg', '18000.000');

        $user = User::whereEmail('actor@example.com')->firstOrFail();
        $this->assertDatabaseHas('carrier_profiles', ['user_id' => $user->id, 'document_number' => 'CAR-001']);
    }

    public function test_ngo_registration_creates_ngo_profile(): void
    {
        $this->seed(RolesSeeder::class);

        $this->postJson('/api/v1/auth/register', $this->payload('ngo', [
            'organization_name' => 'Banco de Alimentos',
            'registration_number' => 'NGO-001',
            'contact_name' => 'Ana Responsável',
            'description' => 'Recebe e redistribui alimentos.',
        ]))->assertCreated()
            ->assertJsonPath('data.profile.organization_name', 'Banco de Alimentos')
            ->assertJsonPath('data.profile.registration_number', 'NGO-001');

        $user = User::whereEmail('actor@example.com')->firstOrFail();
        $this->assertDatabaseHas('ngo_profiles', ['user_id' => $user->id, 'registration_number' => 'NGO-001']);
    }

    public function test_role_specific_fields_cannot_be_sent_by_another_actor(): void
    {
        $this->seed(RolesSeeder::class);

        $payload = $this->payload('buyer', [
            'buyer_type' => 'individual',
            'document_number' => 'BUY-001',
            'service_regions' => ['Campinas'],
        ]);

        $this->postJson('/api/v1/auth/register', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('profile.service_regions');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_missing_actor_specific_required_fields_rolls_back_registration(): void
    {
        $this->seed(RolesSeeder::class);

        $payload = $this->payload('carrier', [
            'company_name' => 'Transportadora',
            'document_number' => 'CAR-001',
        ]);
        unset($payload['profile']['service_regions']);

        $this->postJson('/api/v1/auth/register', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('profile.service_regions');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('carrier_profiles', 0);
    }

    /** @param array<string, mixed> $profile */
    private function payload(string $role, array $profile): array
    {
        if (! function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Sodium extension is required.');
        }
        $keypair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($keypair);
        $wallet = Base58::encode(sodium_crypto_sign_publickey($keypair));
        $challenge = $this->postJson('/api/v1/auth/wallet/challenge', ['wallet_address' => $wallet])
            ->assertCreated()->json('data');

        return [
            'name' => 'Actor FoodRescue',
            'email' => 'actor@example.com',
            'solana_wallet_address' => $wallet,
            'wallet_challenge_id' => $challenge['id'],
            'wallet_signature' => base64_encode(sodium_crypto_sign_detached($challenge['message'], $secret)),
            'password' => 'a-long-test-passphrase',
            'password_confirmation' => 'a-long-test-passphrase',
            'role' => $role,
            'profile' => array_replace([
                'phone' => '+55 11 99999-0000',
                'country' => 'Brazil',
                'state' => 'SP',
                'city' => 'Campinas',
                'address_line' => 'Rua Exemplo, 100',
                'postal_code' => '13000-000',
            ], $profile),
        ];
    }
}
