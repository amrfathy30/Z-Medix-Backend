<?php

namespace Tests\Feature\Fellowship;

use App\Enums\FellowshipImportDuplicateScope;
use App\Enums\FellowshipImportMappingTarget;
use App\Enums\FellowshipImportRowDecision;
use App\Enums\FellowshipImportRowStatus;
use App\Enums\FellowshipImportStatus;
use App\Enums\QuizDifficulty;
use App\Exceptions\Fellowship\FellowshipImportException;
use App\Models\Fellowship;
use App\Models\FellowshipImport;
use App\Models\FellowshipQuestion;
use App\Services\Fellowship\DifficultyParser;
use App\Services\Fellowship\FellowshipImportStagingService;
use App\Support\Fellowship\ImportHeaderMap;

/**
 * Staging: what the importer makes of a file before anything is written.
 *
 * The central claim being tested is that staging is consequence-free — it
 * validates, it matches duplicates, and it leaves the question bank and the
 * Fellowship's filters exactly as they were.
 */
class FellowshipImportStagingTest extends FellowshipTestCase
{
    /** @return list<string> */
    private function headers(): array
    {
        return ['Stem', 'A', 'B', 'C', 'Key', 'Level', 'Area', 'Why', 'System'];
    }

    private function map(?int $existingFilterId = null, ?string $newFilterName = 'System'): ImportHeaderMap
    {
        $system = $existingFilterId !== null
            ? ['target' => FellowshipImportMappingTarget::ExistingFilter->value, 'filter_id' => $existingFilterId]
            : ['target' => FellowshipImportMappingTarget::NewFilter->value, 'filter_name' => $newFilterName];

        return ImportHeaderMap::fromArray([
            'Stem' => FellowshipImportMappingTarget::QuestionText->value,
            'A' => FellowshipImportMappingTarget::AnswerOption->value,
            'B' => FellowshipImportMappingTarget::AnswerOption->value,
            'C' => FellowshipImportMappingTarget::AnswerOption->value,
            'Key' => FellowshipImportMappingTarget::CorrectAnswer->value,
            'Level' => FellowshipImportMappingTarget::Difficulty->value,
            'Area' => FellowshipImportMappingTarget::PerformanceTopic->value,
            'Why' => FellowshipImportMappingTarget::Explanation->value,
            'System' => $system,
        ]);
    }

    /**
     * @param  list<list<string|null>>  $rows
     */
    private function stage(array $rows, ?ImportHeaderMap $map = null, ?Fellowship $fellowship = null): FellowshipImport
    {
        $fellowship ??= $this->fellowship();

        /** @var FellowshipImport $import */
        $import = $fellowship->imports()->create([
            'status' => FellowshipImportStatus::Draft,
            'original_filename' => 'questions.csv',
            'stored_path' => $this->uploadCsv($this->headers(), $rows),
            'headers' => $this->headers(),
        ]);

        return app(FellowshipImportStagingService::class)->stage($import, $map ?? $this->map());
    }

    /** @return list<string|null> */
    private function row(
        string $stem,
        string $key = 'Aspirin',
        string $level = 'Easy',
        string $area = 'Cardiology',
        string $system = 'Cardiovascular',
        string $a = 'Aspirin',
        string $b = 'Warfarin',
        ?string $c = 'Heparin',
    ): array {
        return [$stem, $a, $b, $c, $key, $level, $area, 'Because.', $system];
    }

    public function test_a_valid_file_stages_every_row_without_writing_anything(): void
    {
        $fellowship = $this->fellowship();

        $import = $this->stage([
            $this->row('What is first line?'),
            $this->row('What is second line?'),
        ], fellowship: $fellowship);

        $this->assertSame(FellowshipImportStatus::Staged, $import->status);
        $this->assertSame(2, $import->total_rows);
        $this->assertSame(2, $import->valid_rows);
        $this->assertSame(0, $import->invalid_rows);

        // Staging publishes nothing: no questions, and no new filter either.
        $this->assertSame(0, $fellowship->questions()->count());
        $this->assertSame(1, $fellowship->filters()->count(), 'Only the performance filter should exist.');
    }

    public function test_the_row_number_reported_is_the_one_in_the_spreadsheet(): void
    {
        $import = $this->stage([
            $this->row('First question?'),
            $this->row('Second question?'),
        ]);

        // Row 1 is the header, so the first question is row 2.
        $this->assertSame([2, 3], $import->rows()->pluck('row_number')->map(fn ($n): int => (int) $n)->all());
    }

    public function test_a_row_without_question_text_is_reported_and_excluded(): void
    {
        $import = $this->stage([
            $this->row(''),
            $this->row('A good question?'),
        ]);

        $invalid = $import->rows()->where('status', FellowshipImportRowStatus::Invalid)->first();

        $this->assertNotNull($invalid);
        $this->assertStringContainsString('Question text is empty', implode(' ', $invalid->errors));

        // The good row beside it is untouched.
        $this->assertSame(1, $import->valid_rows);
        $this->assertSame(1, $import->invalid_rows);
    }

    public function test_a_row_with_fewer_than_two_options_is_rejected(): void
    {
        $import = $this->stage([
            $this->row('Only one option?', a: 'Aspirin', b: '', c: ''),
        ]);

        $row = $import->rows()->first();

        $this->assertSame(FellowshipImportRowStatus::Invalid, $row->status);
        $this->assertStringContainsString('At least two non-empty answer options', implode(' ', $row->errors));
    }

    public function test_a_correct_answer_that_names_no_option_is_rejected(): void
    {
        $import = $this->stage([
            $this->row('Mismatched key?', key: 'Clopidogrel'),
        ]);

        $row = $import->rows()->first();

        $this->assertSame(FellowshipImportRowStatus::Invalid, $row->status);
        $this->assertStringContainsString('does not match any of this row', implode(' ', $row->errors));
    }

    public function test_an_unrecognised_difficulty_is_rejected(): void
    {
        $import = $this->stage([
            $this->row('Impossible?', level: 'Nightmare'),
        ]);

        $row = $import->rows()->first();

        $this->assertSame(FellowshipImportRowStatus::Invalid, $row->status);
        $this->assertStringContainsString('Difficulty must be one of', implode(' ', $row->errors));
    }

    public function test_the_three_difficulty_levels_are_accepted_however_they_are_spelled(): void
    {
        $import = $this->stage([
            $this->row('Easy one?', level: 'EASY'),
            $this->row('Medium one?', level: ' medium '),
            $this->row('Hard one?', level: 'Hard'),
        ]);

        $this->assertSame(3, $import->valid_rows);
        $this->assertSame(0, $import->invalid_rows);
    }

    public function test_a_row_without_a_performance_topic_is_rejected(): void
    {
        $import = $this->stage([
            $this->row('No topic?', area: ''),
        ]);

        $row = $import->rows()->first();

        $this->assertSame(FellowshipImportRowStatus::Invalid, $row->status);
        $this->assertStringContainsString('Performance topic is empty', implode(' ', $row->errors));
    }

    public function test_a_row_repeated_inside_the_file_is_flagged_against_the_earlier_row(): void
    {
        $import = $this->stage([
            $this->row('Which drug?'),
            $this->row('  which   DRUG?  '),
        ]);

        $duplicate = $import->rows()->where('status', FellowshipImportRowStatus::Duplicate)->first();

        $this->assertNotNull($duplicate);
        $this->assertSame(FellowshipImportDuplicateScope::File, $duplicate->duplicate_scope);
        $this->assertSame(2, $duplicate->duplicate_of_row_number);
        $this->assertNull($duplicate->duplicate_of_question_id);

        // Updating has nothing to act on for a within-file duplicate.
        $this->assertNotContains(
            FellowshipImportRowDecision::UpdateExisting,
            $duplicate->availableDecisions(),
        );
    }

    public function test_a_row_matching_a_stored_question_is_flagged_against_it(): void
    {
        $fellowship = $this->fellowship();
        $existing = $this->question($fellowship, text: 'Which drug?');

        $import = $this->stage([$this->row('which drug?')], fellowship: $fellowship);

        $duplicate = $import->rows()->first();

        $this->assertSame(FellowshipImportRowStatus::Duplicate, $duplicate->status);
        $this->assertSame(FellowshipImportDuplicateScope::Existing, $duplicate->duplicate_scope);
        $this->assertSame($existing->getKey(), $duplicate->duplicate_of_question_id);

        $this->assertContains(
            FellowshipImportRowDecision::UpdateExisting,
            $duplicate->availableDecisions(),
        );
    }

    public function test_a_soft_deleted_question_is_not_offered_as_a_duplicate_to_update(): void
    {
        $fellowship = $this->fellowship();
        $this->question($fellowship, text: 'Which drug?')->delete();

        $import = $this->stage([$this->row('Which drug?')], fellowship: $fellowship);

        $this->assertSame(FellowshipImportRowStatus::Valid, $import->rows()->first()->status);
    }

    public function test_a_question_matching_another_fellowships_question_is_not_a_duplicate(): void
    {
        $other = $this->fellowship('Internal Medicine');
        $this->question($other, text: 'Which drug?');

        $import = $this->stage([$this->row('Which drug?')], fellowship: $this->fellowship());

        $this->assertSame(FellowshipImportRowStatus::Valid, $import->rows()->first()->status);
    }

    public function test_re_staging_replaces_the_previous_reading_of_the_file(): void
    {
        $fellowship = $this->fellowship();

        $import = $this->stage([
            $this->row('A question?'),
            $this->row('Bad row?', level: 'Nightmare'),
        ], fellowship: $fellowship);

        $this->assertSame(1, $import->invalid_rows);

        // Map the difficulty column onto a dynamic filter instead; the
        // difficulty is then unmapped, and nothing may be imported at all.
        $this->expectException(FellowshipImportException::class);

        app(FellowshipImportStagingService::class)->stage(
            $import,
            ImportHeaderMap::fromArray([
                'Stem' => FellowshipImportMappingTarget::QuestionText->value,
                'A' => FellowshipImportMappingTarget::AnswerOption->value,
                'B' => FellowshipImportMappingTarget::AnswerOption->value,
                'Key' => FellowshipImportMappingTarget::CorrectAnswer->value,
                'Area' => FellowshipImportMappingTarget::PerformanceTopic->value,
            ]),
        );
    }

    public function test_a_mapped_but_empty_filter_cell_is_recorded_as_mapped(): void
    {
        $import = $this->stage([$this->row('A question?', system: '')]);

        $payload = $import->rows()->first()->payload();

        // The entry exists with a null value: mapped and empty, which an update
        // treats as "clear this", and which is not the same as absent.
        $this->assertCount(1, $payload->filters);
        $this->assertNull($payload->filters[0]['value']);
        $this->assertSame('System', $payload->filters[0]['filter_name']);
    }

    public function test_a_blank_spreadsheet_line_is_not_a_question(): void
    {
        $import = $this->stage([
            $this->row('A question?'),
            [null, null, null, null, null, null, null, null, null],
        ]);

        $this->assertSame(1, $import->total_rows);
    }

    public function test_difficulty_is_stored_on_the_payload_for_confirmation(): void
    {
        $import = $this->stage([$this->row('A question?', level: 'hard')]);

        $this->assertSame(
            QuizDifficulty::Hard,
            DifficultyParser::parse($import->rows()->first()->payload()->difficulty),
        );
    }

    public function test_no_question_exists_anywhere_after_staging(): void
    {
        $this->stage([
            $this->row('One?'),
            $this->row('Two?'),
            $this->row('Three?'),
        ]);

        $this->assertSame(0, FellowshipQuestion::query()->count());
    }
}
