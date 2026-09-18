<?php

namespace Database\Factories;

use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class DocumentFactory extends Factory
{
    public function definition(): array
    {
        $title = 'Tài liệu ' . $this->faker->unique()->sentence(3);

        return [
            'title' => $title,
            'slug' => Str::slug($title) . '-' . $this->faker->unique()->numerify('####'),
            'kind' => Document::KIND_FILE,
            'confidentiality' => Document::CONF_INTERNAL,
            'allow_download' => false,
            'enable_watermark' => true,
            'status' => Document::STATUS_PUBLISHED,
            'published_at' => now(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => Document::STATUS_DRAFT, 'published_at' => null]);
    }

    public function downloadable(): static
    {
        return $this->state(fn () => ['allow_download' => true]);
    }

    /** Video nhúng từ nguồn ngoài — giới hạn upload 2MB không đủ cho video. */
    public function video(): static
    {
        return $this->state(fn () => [
            'kind' => Document::KIND_VIDEO,
            'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'video_provider' => 'youtube',
            'video_embed_id' => 'dQw4w9WgXcQ',
            'duration_seconds' => 600,
        ]);
    }

    public function restricted(): static
    {
        return $this->state(fn () => ['confidentiality' => Document::CONF_RESTRICTED]);
    }
}
