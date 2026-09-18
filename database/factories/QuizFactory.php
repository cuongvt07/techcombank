<?php

namespace Database\Factories;

use App\Models\Quiz;
use Illuminate\Database\Eloquent\Factories\Factory;

class QuizFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => 'Bài kiểm tra ' . $this->faker->sentence(3),
            'pass_score' => 70,
            'shuffle_questions' => false,
            'shuffle_options' => false,
            'status' => Quiz::STATUS_PUBLISHED,
        ];
    }
}
