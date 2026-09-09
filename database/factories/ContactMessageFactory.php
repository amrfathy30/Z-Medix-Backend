<?php

namespace Database\Factories;

use App\Enums\ContactMessageStatus;
use App\Models\ContactMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactMessage>
 */
class ContactMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'subject' => fake()->sentence(5),
            'message' => fake()->paragraphs(2, true),
            'status' => ContactMessageStatus::New,
            'replied_by_admin_id' => null,
            'replied_at' => null,
            'admin_reply' => null,
            'metadata' => null,
        ];
    }

    public function replied(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ContactMessageStatus::Replied,
            'admin_reply' => fake()->paragraph(),
            'replied_at' => now(),
        ]);
    }

    public function inReview(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ContactMessageStatus::InReview,
        ]);
    }

    public function spam(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ContactMessageStatus::Spam,
        ]);
    }
}
