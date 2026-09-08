<?php

namespace Tests\Feature;

use App\Models\AgriculturalProduct;
use App\Models\QualityGrade;
use App\Models\User;
use App\UserRole;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CatalogSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_seed_leaves_the_catalog_usable_for_publishing(): void
    {
        $this->seed(CatalogSeeder::class);

        $this->assertGreaterThan(30, AgriculturalProduct::query()->where('active', true)->count());
        $this->assertGreaterThan(3, QualityGrade::query()->where('active', true)->count());
        $this->assertDatabaseHas('agricultural_products', ['slug' => 'tomate', 'name' => 'Tomate', 'active' => true]);
        $this->assertDatabaseHas('quality_grades', ['name' => 'Fora de padrão comercial', 'sort_order' => 3]);
    }

    public function test_running_twice_does_not_duplicate_the_catalog(): void
    {
        $this->seed(CatalogSeeder::class);
        $products = AgriculturalProduct::query()->count();
        $grades = QualityGrade::query()->count();

        $this->seed(CatalogSeeder::class);

        $this->assertSame($products, AgriculturalProduct::query()->count());
        $this->assertSame($grades, QualityGrade::query()->count());
    }

    public function test_seeded_catalog_is_served_by_the_reference_endpoint(): void
    {
        $this->seed(CatalogSeeder::class);
        Sanctum::actingAs(User::factory()->withRole(UserRole::Producer)->create());

        $products = $this->getJson('/api/v1/catalog/products')->assertOk()->json('data');
        $names = array_column($products, 'name');

        $this->assertContains('Couve manteiga', $names);
        $this->assertSame('Abacate', $names[0], 'A listagem chega ordenada por nome.');

        // Produto desativado pelo administrador some da lista oferecida ao produtor.
        AgriculturalProduct::query()->where('slug', 'tomate')->update(['active' => false]);
        $restantes = array_column($this->getJson('/api/v1/catalog/products')->assertOk()->json('data'), 'name');
        $this->assertNotContains('Tomate', $restantes);
    }
}
