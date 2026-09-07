<?php

namespace Database\Seeders;

use App\UserRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $map = [
            UserRole::Admin->value => ['admin.users.manage', 'catalog.manage', 'settings.manage', 'blockchain.protocol.manage'],
            UserRole::Producer->value => ['surplus.create', 'surplus.update', 'offer.respond', 'delivery.ready', 'trade.cancel', 'blockchain.trade.cancel', 'rating.create'],
            UserRole::Buyer->value => ['offer.create', 'trade.create', 'shipping.request.create', 'shipping.select', 'blockchain.trade.fund', 'delivery.transport', 'blockchain.trade.settle', 'trade.cancel', 'blockchain.trade.cancel', 'rating.create'],
            UserRole::Carrier->value => ['shipping.offer.create', 'delivery.transport', 'rating.create'],
            UserRole::Ngo->value => ['rescue.accept', 'shipping.request.create', 'shipping.select', 'blockchain.trade.fund', 'delivery.transport', 'blockchain.trade.settle', 'rescue.proof.create', 'rating.create'],
        ];

        foreach ($map as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web');
            foreach ($permissions as $permissionName) {
                Permission::findOrCreate($permissionName, 'web');
            }
            $role->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
