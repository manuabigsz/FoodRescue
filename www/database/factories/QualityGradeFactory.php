<?php

namespace Database\Factories;

use App\Models\QualityGrade;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<QualityGrade> */
class QualityGradeFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => 'Grade '.fake()->unique()->bothify('??##'), 'description' => fake()->sentence(), 'sort_order' => 0, 'active' => true];
    }
}
