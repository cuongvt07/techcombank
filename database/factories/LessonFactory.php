<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\Lesson;
use Illuminate\Database\Eloquent\Factories\Factory;

class LessonFactory extends Factory
{
    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'title' => 'Bài học ' . $this->faker->sentence(3),
            'content_type' => Lesson::TYPE_TEXT,
            'content_html' => '<p>' . $this->faker->paragraph() . '</p>',
            'sort_order' => 0,
            'is_required' => true,
        ];
    }

    public function optional(): static
    {
        return $this->state(fn () => ['is_required' => false]);
    }

    public function video(int $minWatchPercent = 80): static
    {
        return $this->state(fn () => [
            'content_type' => Lesson::TYPE_VIDEO,
            'content_html' => null,
            'min_watch_percent' => $minWatchPercent,
        ]);
    }

    public function quiz(): static
    {
        return $this->state(fn () => [
            'content_type' => Lesson::TYPE_QUIZ,
            'content_html' => null,
        ]);
    }
}
