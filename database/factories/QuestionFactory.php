<?php

namespace Database\Factories;

use App\Models\Question;
use App\Models\Quiz;
use Illuminate\Database\Eloquent\Factories\Factory;

class QuestionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'quiz_id' => Quiz::factory(),
            'content' => $this->faker->sentence() . '?',
            'type' => Question::TYPE_SINGLE,
            'score' => 1,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    public function multiple(): static
    {
        return $this->state(fn () => ['type' => Question::TYPE_MULTIPLE]);
    }
}
