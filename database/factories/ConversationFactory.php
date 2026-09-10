<?php

namespace Database\Factories;

use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Conversation> */
class ConversationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'type' => ConversationType::Group,
            'name' => fake()->words(2, true),
            'direct_key' => null,
            'owner_id' => User::factory(),
        ];
    }
}
