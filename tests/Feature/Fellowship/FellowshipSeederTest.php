<?php

namespace Tests\Feature\Fellowship;

use App\Models\Fellowship;
use App\Models\FellowshipQuestion;
use App\Models\User;
use Database\Seeders\FellowshipSeeder;

/**
 * The local development data: that it loads, that it can be loaded twice, and
 * that a student can use the bank it creates.
 */
class FellowshipSeederTest extends StudentFellowshipTestCase
{
    public function test_it_loads_the_fellowships_with_valid_questions(): void
    {
        $this->seed(FellowshipSeeder::class);

        $this->assertSame(4, Fellowship::query()->count());
        $this->assertFalse(Fellowship::query()->where('name', 'Obstetrics & Gynecology Board')->value('is_active'));

        $medicine = Fellowship::query()->where('name', 'Internal Medicine Board')->firstOrFail();
        $this->assertSame(30, $medicine->questions()->count());
        $this->assertSame(
            ['Cardiology', 'Endocrinology', 'Gastroenterology', 'Nephrology', 'Neurology', 'Respiratory'],
            $medicine->performanceTopics()->pluck('value')->all(),
        );

        FellowshipQuestion::query()->with('options')->each(function (FellowshipQuestion $question): void {
            $this->assertGreaterThanOrEqual(2, $question->options->count());
            $this->assertSame(1, $question->options->where('is_correct', true)->count());
            $this->assertNotNull($question->performance_filter_value_id);
        });
    }

    public function test_the_correct_answer_is_not_always_in_the_same_position(): void
    {
        $this->seed(FellowshipSeeder::class);

        $positions = FellowshipQuestion::query()->with('options')->get()
            ->map(fn (FellowshipQuestion $question): int => (int) $question->options->firstWhere('is_correct', true)->order)
            ->unique();

        $this->assertGreaterThan(1, $positions->count());
    }

    public function test_running_it_twice_does_not_duplicate_anything(): void
    {
        $this->seed(FellowshipSeeder::class);

        $counts = [Fellowship::query()->count(), FellowshipQuestion::query()->count()];

        $this->seed(FellowshipSeeder::class);

        $this->assertSame($counts, [Fellowship::query()->count(), FellowshipQuestion::query()->count()]);
    }

    public function test_it_creates_no_students(): void
    {
        $this->seed(FellowshipSeeder::class);

        $this->assertSame(0, User::query()->count());
    }

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        try {
            // Called directly: `db:seed` would stop to ask for confirmation.
            (new FellowshipSeeder)->run();
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertSame(0, Fellowship::query()->count());
    }

    public function test_a_student_sees_only_the_active_fellowships(): void
    {
        $this->seed(FellowshipSeeder::class);
        $this->actingAsStudent();

        $names = collect($this->getJson($this->fellowshipsUrl)->assertOk()->json('data'))->pluck('name');

        $this->assertEqualsCanonicalizing(
            ['Internal Medicine Board', 'General Surgery Board', 'Pediatrics Board'],
            $names->all(),
        );
    }

    public function test_a_student_can_filter_count_and_generate_from_the_seeded_bank(): void
    {
        $this->seed(FellowshipSeeder::class);
        $this->actingAsStudent();
        $pediatrics = Fellowship::query()->where('name', 'Pediatrics Board')->firstOrFail();

        $filters = $this->getJson($this->fellowshipsUrl."/{$pediatrics->id}/filters")->assertOk();
        $this->assertNotEmpty($filters->json('data.filters'));

        $this->postJson($this->fellowshipsUrl."/{$pediatrics->id}/available-questions", [
            'title' => 'Preview',
            'question_count' => 10,
        ])
            ->assertOk()
            ->assertJsonPath('data.available_questions', 10)
            ->assertJsonPath('data.can_generate', true);

        $session = $this->generateSession($pediatrics, ['title' => 'First look', 'question_count' => 4]);

        $this->assertCount(4, $session->questions);
        $this->answerCurrent($session)->assertOk();
    }
}
