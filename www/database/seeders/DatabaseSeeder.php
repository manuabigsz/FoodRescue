<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([RolesSeeder::class, PermissionsSeeder::class, CatalogSeeder::class, InitialAdminSeeder::class]);
    }
}
