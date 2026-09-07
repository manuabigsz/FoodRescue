<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\PasswordRules;
use App\UserRole;
use App\UserStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Spatie\Permission\Models\Role;

class InitialAdminSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesSeeder::class);

        DB::transaction(function (): void {
            Role::where('name', UserRole::Admin->value)->where('guard_name', 'web')->lockForUpdate()->firstOrFail();

            if (User::role(UserRole::Admin->value)->exists()) {
                return;
            }

            $credentials = config('accounts.initial_admin');
            $credentials['email'] = is_string($credentials['email'] ?? null)
                ? mb_strtolower(trim($credentials['email'])) : null;
            $credentials['password_confirmation'] = $credentials['password'] ?? null;

            $validator = Validator::make($credentials, [
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'string', 'email:rfc', 'max:254'],
                'password' => PasswordRules::rules(),
            ]);

            if ($validator->fails()) {
                throw new RuntimeException('Configure INITIAL_ADMIN_NAME, INITIAL_ADMIN_EMAIL e INITIAL_ADMIN_PASSWORD válidos. Senha: mínimo 15 caracteres e máximo 72 bytes.');
            }

            if (User::where('email', $credentials['email'])->exists()) {
                throw new RuntimeException('O email do administrador inicial já pertence a outra conta. Nenhuma promoção foi realizada.');
            }

            $user = User::create([
                'name' => $credentials['name'],
                'email' => $credentials['email'],
                'password' => $credentials['password'],
            ]);
            $user->status = UserStatus::Active;
            $user->save();
            $user->assignRole(UserRole::Admin->value);
        }, 3);
    }
}
