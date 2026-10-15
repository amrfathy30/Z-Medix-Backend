<?php

namespace Database\Seeders;

use App\Models\Fellowship;
use App\Models\FellowshipFilter;
use App\Models\FellowshipFilterValue;
use App\Models\FellowshipQuestion;
use App\Support\Fellowship\QuestionTextNormalizer;
use Database\Seeders\Data\FellowshipDemoBank;
use Illuminate\Database\Seeder;

/**
 * Local development data for the Fellowship question banks.
 *
 * Not part of {@see DatabaseSeeder}: it is run by hand —
 *
 *     php artisan db:seed --class=FellowshipSeeder
 *
 * It loads four Fellowships (one inactive) with their filters and questions, so
 * the Fellowship list, filters, available-question count and session
 * generation have a bank to work against. It creates no students and no
 * sessions.
 *
 * Safe to run again: Fellowships, filters, values and questions are matched by
 * name and text, and anything that already exists is left as it is. The
 * questions have not been clinically reviewed, so it refuses to run in
 * production.
 */
class FellowshipSeeder extends Seeder
{
    /** @var array<int, array<string, FellowshipFilterValue>> Filter id => value text => value. */
    private array $values = [];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('FellowshipSeeder loads unreviewed demo questions and is not run in production.');

            return;
        }

        foreach (FellowshipDemoBank::fellowships() as $definition) {
            $this->seedFellowship($definition);
        }

        $this->command?->info('Fellowship questions seeded.');
    }

    /**
     * @param  array{name: string, is_active: bool, items: list<array<string, mixed>>}  $definition
     */
    private function seedFellowship(array $definition): void
    {
        /** @var Fellowship $fellowship */
        $fellowship = Fellowship::query()->firstOrCreate(
            ['name' => $definition['name']],
            ['is_active' => $definition['is_active']],
        );

        $performance = $this->filter($fellowship, FellowshipDemoBank::PERFORMANCE_FILTER, isPerformance: true);

        if ($fellowship->performance_filter_id === null) {
            $fellowship->update(['performance_filter_id' => $performance->getKey()]);
        }

        $dynamic = [
            FellowshipDemoBank::EXAM_FILTER => $this->filter($fellowship, FellowshipDemoBank::EXAM_FILTER),
            FellowshipDemoBank::YEAR_FILTER => $this->filter($fellowship, FellowshipDemoBank::YEAR_FILTER),
        ];

        foreach ($definition['items'] as $index => $item) {
            $this->seedQuestion($fellowship, $performance, $dynamic, $item, $index);
        }
    }

    /**
     * @param  array<string, FellowshipFilter>  $dynamic
     * @param  array<string, mixed>  $item
     */
    private function seedQuestion(Fellowship $fellowship, FellowshipFilter $performance, array $dynamic, array $item, int $index): void
    {
        $hash = QuestionTextNormalizer::hash($item['question']);

        $exists = FellowshipQuestion::query()
            ->forFellowship($fellowship->getKey())
            ->withContentHash($hash)
            ->exists();

        if ($exists) {
            return;
        }

        $question = new FellowshipQuestion([
            'fellowship_id' => $fellowship->getKey(),
            'title' => $item['title'],
            'question_text' => $item['question'],
            'explanation' => $item['explanation'],
            'difficulty' => $item['difficulty'],
            'performance_filter_value_id' => $this->value($performance, $item['topic'])->getKey(),
        ]);

        // The model derives this on save, but not while model events are off,
        // which is the case when this runs from a seeder that uses
        // WithoutModelEvents.
        $question->content_hash = $hash;
        $question->save();

        foreach ($this->orderedOptions($item['options'], $index) as $position => $option) {
            $question->options()->create([
                'option_text' => $option['text'],
                'explanation' => $option['explanation'],
                'is_correct' => $option['is_correct'],
                'order' => $position + 1,
            ]);
        }

        foreach ($item['filters'] as $filterName => $value) {
            $filter = $dynamic[$filterName];

            $question->filterValues()->create([
                'fellowship_filter_id' => $filter->getKey(),
                'fellowship_filter_value_id' => $this->value($filter, $value)->getKey(),
            ]);
        }
    }

    /**
     * The options in the order students see them. The bank lists the correct
     * one first; it is moved to a different slot per question so a client that
     * assumed "the first option" or "option A" would be caught out.
     *
     * @param  list<string|array{0: string, 1: string}>  $options
     * @return list<array{text: string, explanation: string|null, is_correct: bool}>
     */
    private function orderedOptions(array $options, int $index): array
    {
        $normalized = array_map(static fn (string|array $option): array => [
            'text' => is_array($option) ? $option[0] : $option,
            'explanation' => is_array($option) ? $option[1] : null,
            'is_correct' => false,
        ], $options);

        $correct = array_shift($normalized);
        $correct['is_correct'] = true;

        array_splice($normalized, $index % count($options), 0, [$correct]);

        return $normalized;
    }

    private function filter(Fellowship $fellowship, string $name, bool $isPerformance = false): FellowshipFilter
    {
        return FellowshipFilter::query()->firstOrCreate(
            ['fellowship_id' => $fellowship->getKey(), 'name' => $name],
            ['is_performance' => $isPerformance],
        );
    }

    private function value(FellowshipFilter $filter, string $text): FellowshipFilterValue
    {
        return $this->values[$filter->getKey()][$text] ??= $filter->values()->firstOrCreate(['value' => $text]);
    }
}
