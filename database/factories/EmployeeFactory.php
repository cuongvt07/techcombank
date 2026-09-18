<?php

namespace Database\Factories;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmployeeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'employee_code' => 'NV' . $this->faker->unique()->numerify('######'),
            'full_name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'employment_status' => Employee::STATUS_OFFICIAL,
            'joined_at' => now()->subYear(),
            'is_new_hire' => false,
        ];
    }

    public function newHire(): static
    {
        return $this->state(fn () => [
            'is_new_hire' => true,
            'employment_status' => Employee::STATUS_PROBATION,
            'joined_at' => now(),
        ]);
    }

    public function resigned(): static
    {
        return $this->state(fn () => [
            'employment_status' => Employee::STATUS_RESIGNED,
            'resigned_at' => now(),
        ]);
    }
}
