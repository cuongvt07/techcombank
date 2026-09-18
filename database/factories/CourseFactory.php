<?php

namespace Database\Factories;

use App\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CourseFactory extends Factory
{
    public function definition(): array
    {
        $title = 'Khoá học ' . $this->faker->unique()->sentence(3);

        return [
            'code' => 'C-' . $this->faker->unique()->numerify('#####'),
            'title' => $title,
            'slug' => Str::slug($title) . '-' . $this->faker->unique()->numerify('####'),
            'sequential' => true,
            'is_onboarding' => false,
            'status' => Course::STATUS_PUBLISHED,
            'published_at' => now(),
            'issue_certificate' => false,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => Course::STATUS_DRAFT, 'published_at' => null]);
    }

    public function onboarding(): static
    {
        return $this->state(fn () => ['is_onboarding' => true]);
    }

    public function freeOrder(): static
    {
        return $this->state(fn () => ['sequential' => false]);
    }

    public function withCertificate(): static
    {
        return $this->state(fn () => ['issue_certificate' => true]);
    }
}
