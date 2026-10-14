<?php

namespace Database\Factories;

use App\Models\StudentPrivateBook;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentPrivateBook>
 */
class StudentPrivateBookFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'subject_id' => Subject::factory(),
            'title' => fake()->unique()->sentence(3),
            'author' => fake()->name(),
        ];
    }

    public function withoutAuthor(): static
    {
        return $this->state(fn (array $attributes) => [
            'author' => null,
        ]);
    }

    /**
     * A private book with its PDF already in the collection, written to the
     * private disk exactly as an upload would be.
     */
    public function withPdfFile(string $fileName = 'private-book.pdf'): static
    {
        return $this->afterCreating(function (StudentPrivateBook $book) use ($fileName): void {
            $book->addMedia(BookFactory::fakePdf($fileName))
                ->toMediaCollection(StudentPrivateBook::PDF_COLLECTION);

            $book->unsetRelation('media');
        });
    }
}
