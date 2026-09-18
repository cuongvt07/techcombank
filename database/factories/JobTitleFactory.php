<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class JobTitleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'JT-' . $this->faker->unique()->numerify('####'),
            'name' => 'Chức danh ' . $this->faker->unique()->word(),
            'is_active' => true,
        ];
    }
}
