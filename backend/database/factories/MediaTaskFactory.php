<?php

namespace Database\Factories;

use App\Models\MediaTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaTask>
 */
class MediaTaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'source' => 'upload',
            'status' => 'queued',
            'progress' => 0,
            'name' => fake()->words(3, true),
        ];
    }
}
