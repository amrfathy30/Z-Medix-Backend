<?php

namespace Database\Factories;

use App\Models\Book;
use App\Models\BookPage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookPage>
 */
class BookPageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'book_id' => Book::factory()->content(),
            'content' => '<p>'.fake()->paragraph().'</p>',
        ];
    }

    public function order(int $order): static
    {
        return $this->state(fn (array $attributes) => [
            'order' => $order,
        ]);
    }

    public function content(string $content): static
    {
        return $this->state(fn (array $attributes) => [
            'content' => $content,
        ]);
    }
}
