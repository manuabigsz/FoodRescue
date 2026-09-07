<?php

namespace Database\Factories;

use App\Models\AgriculturalProduct;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AgriculturalProduct> */
class AgriculturalProductFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return ['name' => ucfirst($name), 'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999), 'active' => true];
    }
}
