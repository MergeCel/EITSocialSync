<?php

namespace Database\Factories;

use App\Models\BufferConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BufferConnection>
 */
class BufferConnectionFactory extends Factory
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
            'api_key' => 'buffer-test-'.fake()->uuid(),
            'api_key_fingerprint' => hash('sha256', fake()->unique()->uuid()),
            'buffer_account_id' => 'buffer-account-'.fake()->unique()->uuid(),
            'account_name' => fake()->name(),
            'account_email' => fake()->safeEmail(),
            'organization_id' => 'buffer-org-'.fake()->unique()->uuid(),
            'organization_name' => fake()->company(),
            'status' => 'active',
            'last_synced_at' => null,
            'last_error' => null,
        ];
    }
}
