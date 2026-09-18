<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class JobGradeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'GRD-' . $this->faker->unique()->numerify('###'),
            'name' => 'Cấp ' . $this->faker->unique()->numberBetween(1, 99),
            'level' => $this->faker->numberBetween(1, 10),
            'is_active' => true,
        ];
    }
}
