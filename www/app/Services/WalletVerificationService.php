<?php

namespace App\Services;

use App\Models\User;
use App\Models\WalletChallenge;
use App\Support\Base58;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WalletVerificationService
{
    /** @return array{id:int,wallet_address:string,message:string,expires_at:string} */
    public function createRegistrationChallenge(string $walletAddress): array
    {
        return $this->createChallenge(null, $walletAddress, WalletChallenge::PURPOSE_REGISTRATION);
    }

    /** @return array{id:int,wallet_address:string,message:string,expires_at:string} */
    public function createUserChallenge(User $user, string $walletAddress): array
    {
        return $this->createChallenge($user, $walletAddress, WalletChallenge::PURPOSE_VERIFY);
    }

    /** @return array{id:int,wallet_address:string,message:string,expires_at:string} */
    public function createSurplusPublicationChallenge(User $user): array
    {
        if (! $user->solana_wallet_address || ! $user->solana_wallet_verified_at) {
            throw ValidationException::withMessages(['wallet' => 'O produtor precisa ter uma carteira Solana verificada.']);
        }

        return $this->createChallenge($user, $user->solana_wallet_address, WalletChallenge::PURPOSE_SURPLUS_PUBLICATION);
    }

    public function consumeSurplusPublicationChallenge(User $user, int $challengeId, string $signature): void
    {
        DB::transaction(function () use ($user, $challengeId, $signature): void {
            $challenge = WalletChallenge::whereKey($challengeId)->lockForUpdate()->first();
            $this->assertUsable($challenge, (string) $user->solana_wallet_address, WalletChallenge::PURPOSE_SURPLUS_PUBLICATION, $user->id);
            $this->assertSignature($challenge, $signature);
            $challenge->update(['used_at' => now()]);
        }, 3);
    }

    public function consumeRegistrationChallenge(int $challengeId, string $walletAddress, string $signature): void
    {
        DB::transaction(function () use ($challengeId, $walletAddress, $signature): void {
            $challenge = WalletChallenge::whereKey($challengeId)->lockForUpdate()->first();
            $this->assertUsable($challenge, $walletAddress, WalletChallenge::PURPOSE_REGISTRATION, null);
            $this->assertSignature($challenge, $signature);
            $challenge->update(['used_at' => now()]);
        }, 3);
    }

    public function verifyForUser(User $actor, int $challengeId, string $signature): User
    {
        return DB::transaction(function () use ($actor, $challengeId, $signature): User {
            $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $challenge = WalletChallenge::whereKey($challengeId)->lockForUpdate()->first();
            $this->assertUsable($challenge, (string) $challenge?->wallet_address, WalletChallenge::PURPOSE_VERIFY, $user->id);
            $this->assertSignature($challenge, $signature);

            $conflict = User::query()
                ->where('solana_wallet_address', $challenge->wallet_address)
                ->where('id', '!=', $user->id)
                ->exists();
            if ($conflict) {
                throw ValidationException::withMessages(['wallet_address' => 'Esta wallet já pertence a outra conta.']);
            }

            $user->solana_wallet_address = $challenge->wallet_address;
            $user->solana_wallet_verified_at = now();
            $user->save();
            $challenge->update(['used_at' => now()]);
            WalletChallenge::query()
                ->where('user_id', $user->id)
                ->whereNull('used_at')
                ->where('id', '!=', $challenge->id)
                ->update(['used_at' => now()]);

            return $user->load(['roles', 'producerProfile', 'buyerProfile', 'carrierProfile', 'ngoProfile']);
        }, 3);
    }

    /** @return array{id:int,wallet_address:string,message:string,expires_at:string} */
    private function createChallenge(?User $user, string $walletAddress, string $purpose): array
    {
        $ttl = max(1, min(30, (int) config('accounts.wallet_challenge_ttl_minutes', 5)));
        $issuedAt = now()->utc();
        $expiresAt = $issuedAt->copy()->addMinutes($ttl);
        $nonce = (string) Str::uuid();
        $message = implode("\n", array_filter([
            'FoodRescue Wallet Verification',
            'Version: 1',
            'Purpose: '.$purpose,
            'Wallet: '.$walletAddress,
            $user ? 'User ID: '.$user->id : null,
            'Nonce: '.$nonce,
            'Issued At: '.$issuedAt->toIso8601String(),
            'Expires At: '.$expiresAt->toIso8601String(),
        ]));

        $challenge = WalletChallenge::create([
            'user_id' => $user?->id,
            'wallet_address' => $walletAddress,
            'purpose' => $purpose,
            'nonce' => $nonce,
            'message' => $message,
            'expires_at' => $expiresAt,
        ]);

        return [
            'id' => $challenge->id,
            'wallet_address' => $walletAddress,
            'message' => $message,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    private function assertUsable(?WalletChallenge $challenge, string $walletAddress, string $purpose, ?int $userId): void
    {
        if (! $challenge || $challenge->purpose !== $purpose || $challenge->wallet_address !== $walletAddress || $challenge->user_id !== $userId) {
            throw ValidationException::withMessages(['challenge_id' => 'Challenge de wallet inválido.']);
        }
        if ($challenge->used_at !== null) {
            throw ValidationException::withMessages(['challenge_id' => 'Este challenge já foi utilizado.']);
        }
        if ($challenge->expires_at->isPast()) {
            throw ValidationException::withMessages(['challenge_id' => 'Este challenge expirou.']);
        }
    }

    private function assertSignature(WalletChallenge $challenge, string $signature): void
    {
        if (! function_exists('sodium_crypto_sign_verify_detached')) {
            abort(500, 'A extensão Sodium do PHP é obrigatória para verificar wallets Solana.');
        }

        $signatureBytes = base64_decode($signature, true);
        $publicKey = Base58::decode($challenge->wallet_address);
        $valid = is_string($signatureBytes)
            && strlen($signatureBytes) === 64
            && strlen($publicKey) === 32
            && sodium_crypto_sign_verify_detached($signatureBytes, $challenge->message, $publicKey);

        if (! $valid) {
            throw ValidationException::withMessages(['signature' => 'A assinatura não comprova posse desta wallet.']);
        }
    }
}
