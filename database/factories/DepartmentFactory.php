<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class DepartmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'DEP-' . $this->faker->unique()->numerify('####'),
            'name' => 'Phòng ' . $this->faker->unique()->word(),
            'is_active' => true,
        ];
    }
}
