<?php

namespace App\Services;

use App\Models\BuyerProfile;
use App\Models\CarrierProfile;
use App\Models\NgoProfile;
use App\Models\ProducerProfile;
use App\Models\User;
use App\UserRole;
use App\UserStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;
use Spatie\Permission\Models\Role;

class Accounts
{
    public function __construct(private readonly WalletVerificationService $wallets) {}

    /** @param array{name: string, email: string, password: string, role: string, solana_wallet_address?: string, wallet_challenge_id?: int, wallet_signature?: string, profile?: array<string, mixed>} $data */
    public function register(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            if (($data['role'] ?? null) !== UserRole::Admin->value) {
                $this->wallets->consumeRegistrationChallenge(
                    (int) ($data['wallet_challenge_id'] ?? 0),
                    (string) ($data['solana_wallet_address'] ?? ''),
                    (string) ($data['wallet_signature'] ?? ''),
                );
            }

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'solana_wallet_address' => $data['solana_wallet_address'] ?? null,
                'solana_wallet_verified_at' => ($data['role'] ?? null) === UserRole::Admin->value ? null : now(),
                'password' => $data['password'],
            ]);
            $user->status = UserStatus::Active;
            $user->save();
            $user->assignRole($data['role']);
            $this->createActorProfile($user, UserRole::from($data['role']), $data['profile'] ?? []);

            return $this->loadAccount($user);
        });
    }

    /** @return array{user: User, token: NewAccessToken} */
    public function login(string $email, string $password, string $device): array
    {
        return (new Timebox)->call(function () use ($email, $password, $device): array {
            $user = User::where('email', $email)->first();
            $validPassword = $user && Hash::check($password, $user->password);
            abort_unless($validPassword && $user->status === UserStatus::Active, 401, 'Credenciais inválidas.');

            return DB::transaction(function () use ($user, $password, $device): array {
                $lockedUser = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                abort_unless(
                    $lockedUser->status === UserStatus::Active && hash_equals($lockedUser->password, $user->password),
                    401, 'Credenciais inválidas.',
                );

                if (Hash::needsRehash($lockedUser->password)) {
                    $lockedUser->password = Hash::make($password);
                    $lockedUser->save();
                }

                $lockedUser->tokens()->where('expires_at', '<=', now())->delete();
                $keep = $lockedUser->tokens()->latest('id')
                    ->limit(max(0, (int) config('accounts.max_tokens_per_user') - 1))->pluck('id');
                $lockedUser->tokens()->whereNotIn('id', $keep)->delete();

                $token = $lockedUser->createToken(
                    $device, ['*'],
                    now()->addMinutes(max(1, (int) config('accounts.token_lifetime_minutes'))),
                );

                return ['user' => $this->loadAccount($lockedUser), 'token' => $token];
            }, 3);
        }, 350000);
    }

    /** @param array<string, mixed> $data */
    public function updateProfile(User $actor, array $data): User
    {
        return DB::transaction(function () use ($actor, $data): User {
            $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->status === UserStatus::Active, 403, 'Conta bloqueada.');

            if (array_key_exists('email', $data)) {
                $this->checkPassword($user, $data['current_password']);
                if ($user->email !== $data['email']) {
                    $user->email = $data['email'];
                    $user->email_verified_at = null;
                    $user->tokens()->delete();
                }
            }

            if (array_key_exists('name', $data)) {
                $user->name = $data['name'];
            }

            $user->save();

            return $this->loadAccount($user);
        }, 3);
    }

    public function changePassword(User $actor, string $currentPassword, string $password): void
    {
        DB::transaction(function () use ($actor, $currentPassword, $password): void {
            $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->status === UserStatus::Active, 403, 'Conta bloqueada.');
            $this->checkPassword($user, $currentPassword);
            $user->password = $password;
            $user->save();
            $user->tokens()->delete();
        }, 3);
    }

    /** @param array<string, string> $data */
    public function createAdmin(User $actor, array $data): User
    {
        $user = DB::transaction(function () use ($actor, $data): User {
            $this->lockAdminRole();
            $admin = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($admin)->authorize('createAdmin', User::class);
            $this->checkPassword($admin, $data['current_password']);

            return $this->register([
                'name' => $data['name'],
                'email' => $data['email'],
                'solana_wallet_address' => $data['solana_wallet_address'] ?? null,
                'solana_wallet_verified_at' => ($data['role'] ?? null) === UserRole::Admin->value ? null : now(),
                'password' => $data['password'],
                'role' => UserRole::Admin->value,
            ]);
        }, 3);

        Log::notice('accounts.admin_created', ['actor_id' => $actor->id, 'user_id' => $user->id]);

        return $user;
    }

    public function updateStatus(User $actor, User $target, UserStatus $status): User
    {
        $user = DB::transaction(function () use ($actor, $target, $status): User {
            $this->lockAdminRole();
            $admin = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $user = User::whereKey($target->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($admin)->authorize('updateStatus', $user);

            abort_if($admin->is($user) && $status === UserStatus::Blocked, 409, 'Não é permitido bloquear a própria conta.');

            $user->status = $status;
            $user->save();
            if ($status === UserStatus::Blocked) {
                $user->tokens()->delete();
            }

            return $this->loadAccount($user);
        }, 3);

        Log::notice('accounts.status_changed', [
            'actor_id' => $actor->id, 'user_id' => $user->id, 'status' => $status->value,
        ]);

        return $user;
    }

    /** @param array<string, mixed> $profile */
    private function createActorProfile(User $user, UserRole $role, array $profile): void
    {
        match ($role) {
            UserRole::Producer => ProducerProfile::create(['user_id' => $user->id, ...$profile]),
            UserRole::Buyer => BuyerProfile::create(['user_id' => $user->id, ...$profile]),
            UserRole::Carrier => CarrierProfile::create(['user_id' => $user->id, ...$profile]),
            UserRole::Ngo => NgoProfile::create(['user_id' => $user->id, ...$profile]),
            UserRole::Admin => null,
        };
    }

    private function loadAccount(User $user): User
    {
        return $user->load(['roles', 'producerProfile', 'buyerProfile', 'carrierProfile', 'ngoProfile']);
    }

    private function checkPassword(User $user, string $password): void
    {
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'A senha atual está incorreta.']);
        }
    }

    private function lockAdminRole(): void
    {
        // Serialize administrative mutations so concurrent blocking cannot disable every administrator.
        Role::where('name', UserRole::Admin->value)->where('guard_name', 'web')->lockForUpdate()->firstOrFail();
    }
}
