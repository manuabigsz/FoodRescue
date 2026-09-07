<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Support\Base58;
use App\UserRole;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WalletVerificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_authenticated_user_can_prove_and_change_wallet_with_ed25519_signature(): void
    {
        if (! function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Sodium required.');
        }
        $user = User::factory()->withRole(UserRole::Buyer)->create();
        Sanctum::actingAs($user);

        $keypair = sodium_crypto_sign_keypair();
        $wallet = Base58::encode(sodium_crypto_sign_publickey($keypair));
        $secret = sodium_crypto_sign_secretkey($keypair);

        $challenge = $this->postJson('/api/v1/auth/wallet/change/challenge', ['wallet_address' => $wallet])
            ->assertCreated()->json('data');
        $signature = base64_encode(sodium_crypto_sign_detached($challenge['message'], $secret));

        $this->postJson('/api/v1/auth/wallet/change/verify', [
            'challenge_id' => $challenge['id'], 'signature' => $signature,
        ])->assertOk()
            ->assertJsonPath('data.solana_wallet_address', $wallet)
            ->assertJsonPath('data.solana_wallet_verified', true);

        $this->assertNotNull($user->fresh()->solana_wallet_verified_at);

        $this->postJson('/api/v1/auth/wallet/change/verify', [
            'challenge_id' => $challenge['id'], 'signature' => $signature,
        ])->assertUnprocessable();
    }

    public function test_invalid_signature_does_not_verify_wallet(): void
    {
        if (! function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Sodium required.');
        }
        $user = User::factory()->withRole(UserRole::Buyer)->create();
        Sanctum::actingAs($user);

        $owner = sodium_crypto_sign_keypair();
        $attacker = sodium_crypto_sign_keypair();
        $wallet = Base58::encode(sodium_crypto_sign_publickey($owner));
        $challenge = $this->postJson('/api/v1/auth/wallet/change/challenge', ['wallet_address' => $wallet])
            ->assertCreated()->json('data');

        $signature = base64_encode(sodium_crypto_sign_detached(
            $challenge['message'], sodium_crypto_sign_secretkey($attacker)
        ));
        $this->postJson('/api/v1/auth/wallet/change/verify', [
            'challenge_id' => $challenge['id'], 'signature' => $signature,
        ])->assertUnprocessable()->assertJsonValidationErrors('signature');

        $this->assertNull($user->fresh()->solana_wallet_verified_at);
    }
}
