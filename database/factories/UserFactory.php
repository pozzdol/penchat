<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            // Must satisfy User::USERNAME_REGEX — a letter, then 2-19 of
            // [a-z0-9_]. The numeric suffix is what keeps it unique.
            'username' => 'u'.fake()->unique()->numberBetween(1000, 999999),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
