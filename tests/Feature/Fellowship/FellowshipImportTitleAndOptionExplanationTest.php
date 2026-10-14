<?php

namespace Tests\Feature\Fellowship;

use App\Enums\FellowshipImportDuplicateScope;
use App\Enums\FellowshipImportMappingTarget;
use App\Enums\FellowshipImportRowDecision;
use App\Enums\FellowshipImportStatus;
use App\Enums\QuizDifficulty;
use App\Models\Fellowship;
use App\Models\FellowshipImport;
use App\Models\FellowshipQuestion;
use App\Services\Fellowship\FellowshipImportConfirmationService;
use App\Services\Fellowship\FellowshipImportStagingService;
use App\Support\Fellowship\DuplicateComparison;
use App\Support\Fellowship\ImportHeaderMap;

/**
 * Importing the optional question title and the per-option explanations.
 *
 * Both are optional columns, so the central question is what an import that
 * does not mention them does: nothing. A file without them imports exactly as
 * it did before, and an Update Existing that does not map them leaves what the
 * admin already wrote alone — while a column mapped and left blank clears the
 * value, which is how every other optional field here behaves.
 */
class FellowshipImportTitleAndOptionExplanationTest extends FellowshipTestCase
{
    /**
     * A file with a title column and an explanation column for each of its two
     * answer options.
     *
     * @return list<string>
     */
    private function headers(): array
    {
        return ['Stem', 'Heading', 'A', 'Why A', 'B', 'Why B', 'Key', 'Level', 'Area', 'Reason'];
    }

    /**
     * @param  array<string, array<string, mixed>|string>  $overrides
     */
    private function map(array $overrides = []): ImportHeaderMap
    {
        return ImportHeaderMap::fromArray([
            'Stem' => FellowshipImportMappingTarget::QuestionText->value,
            'Heading' => FellowshipImportMappingTarget::Title->value,
            'A' => FellowshipImportMappingTarget::AnswerOption->value,
            'Why A' => [
                'target' => FellowshipImportMappingTarget::AnswerOptionExplanation->value,
                'option_slot' => 1,
            ],
            'B' => FellowshipImportMappingTarget::AnswerOption->value,
            'Why B' => [
                'target' => FellowshipImportMappingTarget::AnswerOptionExplanation->value,
                'option_slot' => 2,
            ],
            'Key' => FellowshipImportMappingTarget::CorrectAnswer->value,
            'Level' => FellowshipImportMappingTarget::Difficulty->value,
            'Area' => FellowshipImportMappingTarget::PerformanceTopic->value,
            'Reason' => FellowshipImportMappingTarget::Explanation->value,
            ...$overrides,
        ]);
    }

    /**
     * The same file read without the title column, as an older import of the
     * same shape would have read it.
     */
    private function mapWithoutTitle(): ImportHeaderMap
    {
        return $this->map(['Heading' => FellowshipImportMappingTarget::Ignore->value]);
    }

    /**
     * The same file read without the question-level Explanation column.
     */
    private function mapWithoutExplanation(): ImportHeaderMap
    {
        return $this->map(['Reason' => FellowshipImportMappingTarget::Ignore->value]);
    }

    /**
     * The same file read without either per-option explanation column.
     */
    private function mapWithoutOptionExplanations(): ImportHeaderMap
    {
        return $this->map([
            'Why A' => FellowshipImportMappingTarget::Ignore->value,
            'Why B' => FellowshipImportMappingTarget::Ignore->value,
        ]);
    }

    /** @return list<string|null> */
    private function row(
        string $stem = 'Which antiplatelet?',
        ?string $heading = 'Antiplatelet choice',
        ?string $whyA = 'Correct: first line.',
        ?string $whyB = 'Incorrect: an anticoagulant.',
        string $key = 'Aspirin',
        ?string $reason = 'Question-level reasoning.',
    ): array {
        return [$stem, $heading, 'Aspirin', $whyA, 'Warfarin', $whyB, $key, 'Easy', 'Cardiology', $reason];
    }

    /**
     * @param  list<list<string|null>>  $rows
     */
    private function stage(Fellowship $fellowship, array $rows, ?ImportHeaderMap $map = null): FellowshipImport
    {
        /** @var FellowshipImport $import */
        $import = $fellowship->imports()->create([
            'status' => FellowshipImportStatus::Draft,
            'original_filename' => 'questions.csv',
            'stored_path' => $this->uploadCsv($this->headers(), $rows),
            'headers' => $this->headers(),
        ]);

        return app(FellowshipImportStagingService::class)->stage($import, $map ?? $this->map());
    }

    private function confirm(FellowshipImport $import): FellowshipImport
    {
        return app(FellowshipImportConfirmationService::class)->confirm($import);
    }

    private function decide(FellowshipImport $import, FellowshipImportRowDecision $decision): void
    {
        app(FellowshipImportConfirmationService::class)
            ->decideMany($import->duplicateRows()->get(), $decision);
    }

    public function test_an_import_writes_the_title_and_an_explanation_per_option(): void
    {
        $fellowship = $this->fellowship();

        $import = $this->confirm($this->stage($fellowship, [$this->row()]));

        $this->assertSame(1, $import->created_questions);

        /** @var FellowshipQuestion $question */
        $question = $fellowship->questions()->with('options')->sole();

        $this->assertSame('Antiplatelet choice', $question->title);
        $this->assertSame(
            ['Correct: first line.', 'Incorrect: an anticoagulant.'],
            $question->options->pluck('explanation')->all(),
        );
        $this->assertSame('Aspirin', $question->options->firstWhere('is_correct', true)?->option_text);
    }

    public function test_a_file_mapping_neither_column_imports_as_before(): void
    {
        $fellowship = $this->fellowship();

        $import = $this->confirm($this->stage(
            $fellowship,
            [$this->row()],
            $this->map([
                'Heading' => FellowshipImportMappingTarget::Ignore->value,
                'Why A' => FellowshipImportMappingTarget::Ignore->value,
                'Why B' => FellowshipImportMappingTarget::Ignore->value,
            ]),
        ));

        $this->assertSame(1, $import->created_questions);

        /** @var FellowshipQuestion $question */
        $question = $fellowship->questions()->with('options')->sole();

        $this->assertNull($question->title);
        $this->assertSame([null, null], $question->options->pluck('explanation')->all());
        $this->assertSame(2, $question->options->count());
    }

    public function test_a_mapped_but_empty_title_cell_is_imported_as_no_title(): void
    {
        $fellowship = $this->fellowship();

        $this->confirm($this->stage($fellowship, [$this->row(heading: null)]));

        $this->assertNull($fellowship->questions()->sole()->title);
    }

    public function test_an_explanation_lands_on_the_option_its_column_was_mapped_to(): void
    {
        $fellowship = $this->fellowship();

        // Only the second option is explained.
        $this->confirm($this->stage(
            $fellowship,
            [$this->row(whyA: null)],
            $this->map(['Why A' => FellowshipImportMappingTarget::Ignore->value]),
        ));

        /** @var FellowshipQuestion $question */
        $question = $fellowship->questions()->with('options')->sole();

        $this->assertSame([null, 'Incorrect: an anticoagulant.'], $question->options->pluck('explanation')->all());
    }

    public function test_a_title_longer_than_the_column_excludes_its_own_row(): void
    {
        $fellowship = $this->fellowship();

        $import = $this->stage($fellowship, [
            $this->row(stem: 'Too long?', heading: str_repeat('a', 256)),
            $this->row(stem: 'Fine?'),
        ]);

        $this->assertSame(1, $import->invalid_rows);
        $this->assertSame(1, $import->valid_rows);

        $this->confirm($import);

        // The valid row beside it still goes in.
        $this->assertSame(['Fine?'], $fellowship->questions()->pluck('question_text')->all());
    }

    public function test_update_existing_preserves_a_title_the_file_does_not_map(): void
    {
        $fellowship = $this->fellowship();
        $existing = $this->question($fellowship, text: 'Which antiplatelet?', options: 2);
        $existing->update(['title' => 'Written by hand']);

        $import = $this->stage($fellowship, [$this->row()], $this->mapWithoutTitle());

        $this->assertSame(1, $import->duplicate_rows);
        $this->assertSame(
            FellowshipImportDuplicateScope::Existing,
            $import->duplicateRows()->sole()->duplicate_scope,
        );

        $this->decide($import, FellowshipImportRowDecision::UpdateExisting);
        $this->assertSame(1, $this->confirm($import)->updated_questions);

        $this->assertSame('Written by hand', $existing->refresh()->title);
    }

    public function test_update_existing_overwrites_a_title_the_file_does_map(): void
    {
        $fellowship = $this->fellowship();
        $existing = $this->question($fellowship, text: 'Which antiplatelet?', options: 2);
        $existing->update(['title' => 'Written by hand']);

        $import = $this->stage($fellowship, [$this->row(heading: 'From the file')]);
        $this->decide($import, FellowshipImportRowDecision::UpdateExisting);
        $this->confirm($import);

        $this->assertSame('From the file', $existing->refresh()->title);
    }

    public function test_update_existing_clears_a_title_whose_mapped_cell_is_empty(): void
    {
        $fellowship = $this->fellowship();
        $existing = $this->question($fellowship, text: 'Which antiplatelet?', options: 2);
        $existing->update(['title' => 'Written by hand']);

        $import = $this->stage($fellowship, [$this->row(heading: null)]);
        $this->decide($import, FellowshipImportRowDecision::UpdateExisting);
        $this->confirm($import);

        $this->assertNull($existing->refresh()->title);
    }

    /**
     * The question's own explanation follows the same rule as the title and the
     * per-option explanations: absent from the file means absent from the
     * update, not an instruction to erase it. This is also what the duplicate
     * review screen shows the admin before they choose Update Existing.
     */
    public function test_update_existing_preserves_an_explanation_the_file_does_not_map(): void
    {
        $fellowship = $this->fellowship();
        $existing = $this->question($fellowship, text: 'Which antiplatelet?', options: 2);
        $existing->update(['explanation' => 'Written by hand.']);

        $import = $this->stage($fellowship, [$this->row()], $this->mapWithoutExplanation());
        $this->decide($import, FellowshipImportRowDecision::UpdateExisting);

        $this->assertSame(1, $this->confirm($import)->updated_questions);

        // The rest of the row still applied; only the unmapped column was left
        // alone.
        $existing->refresh();

        $this->assertSame('Written by hand.', $existing->explanation);
        $this->assertSame(QuizDifficulty::Easy, $existing->difficulty);
    }

    public function test_update_existing_overwrites_an_explanation_the_file_does_map(): void
    {
        $fellowship = $this->fellowship();
        $existing = $this->question($fellowship, text: 'Which antiplatelet?', options: 2);
        $existing->update(['explanation' => 'Written by hand.']);

        $import = $this->stage($fellowship, [$this->row(reason: 'From the file.')]);
        $this->decide($import, FellowshipImportRowDecision::UpdateExisting);
        $this->confirm($import);

        $this->assertSame('From the file.', $existing->refresh()->explanation);
    }

    public function test_update_existing_clears_an_explanation_whose_mapped_cell_is_empty(): void
    {
        $fellowship = $this->fellowship();
        $existing = $this->question($fellowship, text: 'Which antiplatelet?', options: 2);
        $existing->update(['explanation' => 'Written by hand.']);

        $import = $this->stage($fellowship, [$this->row(reason: null)]);
        $this->decide($import, FellowshipImportRowDecision::UpdateExisting);
        $this->confirm($import);

        $this->assertNull($existing->refresh()->explanation);
    }

    public function test_a_new_question_keeps_a_null_explanation_when_the_column_is_unmapped(): void
    {
        $fellowship = $this->fellowship();

        $this->confirm($this->stage($fellowship, [$this->row()], $this->mapWithoutExplanation()));

        $this->assertNull($fellowship->questions()->sole()->explanation);
    }

    public function test_the_duplicate_review_marks_an_unmapped_explanation_as_not_in_the_import(): void
    {
        $fellowship = $this->fellowship();
        $existing = $this->question($fellowship, text: 'Which antiplatelet?', options: 2);
        $existing->update(['explanation' => 'Written by hand.']);

        $import = $this->stage($fellowship, [$this->row()], $this->mapWithoutExplanation());

        $comparison = DuplicateComparison::build($import->duplicateRows()->sole(), $existing);
        $field = collect($comparison->fields)->firstWhere('label', 'Explanation');

        // What the admin is shown and what the writer then does must agree.
        $this->assertFalse($field['in_import']);
        $this->assertFalse($field['conflicts']);
        $this->assertSame('Written by hand.', $field['existing']);
    }

    public function test_update_existing_preserves_option_explanations_the_file_does_not_map(): void
    {
        $fellowship = $this->fellowship();
        $existing = $this->question($fellowship, text: 'Which antiplatelet?', options: 2);
        $existing->options->each(fn ($option) => $option->update([
            'explanation' => 'Hand-written for '.$option->option_text,
        ]));
        $byHand = $existing->options->pluck('explanation')->all();

        $import = $this->stage($fellowship, [$this->row()], $this->mapWithoutOptionExplanations());
        $this->decide($import, FellowshipImportRowDecision::UpdateExisting);
        $this->confirm($import);

        $existing->refresh()->load('options');

        // The option set was rewritten from the file, and the explanations the
        // file said nothing about came across with it.
        $this->assertSame(['Aspirin', 'Warfarin'], $existing->options->pluck('option_text')->all());
        $this->assertSame($byHand, $existing->options->pluck('explanation')->all());
    }

    public function test_update_existing_writes_the_option_explanations_the_file_does_map(): void
    {
        $fellowship = $this->fellowship();
        $existing = $this->question($fellowship, text: 'Which antiplatelet?', options: 2);
        $existing->options->each->update(['explanation' => 'Stale.']);

        $import = $this->stage($fellowship, [$this->row()]);
        $this->decide($import, FellowshipImportRowDecision::UpdateExisting);
        $this->confirm($import);

        $existing->refresh()->load('options');

        $this->assertSame(
            ['Correct: first line.', 'Incorrect: an anticoagulant.'],
            $existing->options->pluck('explanation')->all(),
        );
    }

    public function test_update_existing_clears_an_option_explanation_whose_mapped_cell_is_empty(): void
    {
        $fellowship = $this->fellowship();
        $existing = $this->question($fellowship, text: 'Which antiplatelet?', options: 2);
        $existing->options->each->update(['explanation' => 'Stale.']);

        $import = $this->stage($fellowship, [$this->row(whyA: null)]);
        $this->decide($import, FellowshipImportRowDecision::UpdateExisting);
        $this->confirm($import);

        $existing->refresh()->load('options');

        $this->assertSame([null, 'Incorrect: an anticoagulant.'], $existing->options->pluck('explanation')->all());
    }

    public function test_skip_leaves_the_stored_title_and_option_explanations_untouched(): void
    {
        $fellowship = $this->fellowship();
        $existing = $this->question($fellowship, text: 'Which antiplatelet?', options: 2);
        $existing->update(['title' => 'Kept']);
        $existing->options->each->update(['explanation' => 'Kept too.']);

        $import = $this->stage($fellowship, [$this->row()]);
        $this->decide($import, FellowshipImportRowDecision::Skip);

        $this->assertSame(1, $this->confirm($import)->skipped_questions);

        $existing->refresh()->load('options');

        $this->assertSame('Kept', $existing->title);
        $this->assertSame(['Kept too.', 'Kept too.'], $existing->options->pluck('explanation')->all());
        $this->assertSame(1, $fellowship->questions()->count());
    }

    public function test_keep_both_inserts_a_second_question_carrying_the_files_values(): void
    {
        $fellowship = $this->fellowship();
        $existing = $this->question($fellowship, text: 'Which antiplatelet?', options: 2);
        $existing->update(['title' => 'The original']);

        $import = $this->stage($fellowship, [$this->row(heading: 'The newcomer')]);
        $this->decide($import, FellowshipImportRowDecision::KeepBoth);
        $this->confirm($import);

        $this->assertSame('The original', $existing->refresh()->title);

        /** @var FellowshipQuestion $inserted */
        $inserted = $fellowship->questions()->with('options')->where('title', 'The newcomer')->sole();

        $this->assertSame(
            ['Correct: first line.', 'Incorrect: an anticoagulant.'],
            $inserted->options->pluck('explanation')->all(),
        );
    }

    public function test_a_title_never_affects_duplicate_detection(): void
    {
        $fellowship = $this->fellowship();
        $existing = $this->question($fellowship, text: 'Which antiplatelet?', options: 2);
        $existing->update(['title' => 'Quite a different heading']);

        // Same question text, a title that shares nothing with the stored one:
        // still the same question.
        $import = $this->stage($fellowship, [$this->row(heading: 'Nothing alike')]);

        $this->assertSame(1, $import->duplicate_rows);
        $this->assertSame(0, $import->valid_rows);
    }

    public function test_the_correct_answer_still_resolves_by_letter_and_by_text(): void
    {
        $fellowship = $this->fellowship();

        $import = $this->confirm($this->stage($fellowship, [
            $this->row(stem: 'By text?', key: 'Warfarin'),
            $this->row(stem: 'By letter?', key: 'A'),
            $this->row(stem: 'By number?', key: '2'),
        ]));

        $this->assertSame(3, $import->created_questions);

        $correctFor = fn (string $text): ?string => $fellowship->questions()
            ->with('options')
            ->where('question_text', $text)
            ->sole()
            ->options
            ->firstWhere('is_correct', true)
            ?->option_text;

        $this->assertSame('Warfarin', $correctFor('By text?'));
        $this->assertSame('Aspirin', $correctFor('By letter?'));
        $this->assertSame('Warfarin', $correctFor('By number?'));
    }

    public function test_a_stored_map_without_option_slots_still_reads_its_explanation_columns(): void
    {
        // A map as an older wizard would have stored it: the entry carries no
        // option slot, so the columns fall back to the order they were mapped.
        $map = ImportHeaderMap::fromArray([
            'Stem' => FellowshipImportMappingTarget::QuestionText->value,
            'A' => FellowshipImportMappingTarget::AnswerOption->value,
            'Why A' => FellowshipImportMappingTarget::AnswerOptionExplanation->value,
            'B' => FellowshipImportMappingTarget::AnswerOption->value,
            'Why B' => FellowshipImportMappingTarget::AnswerOptionExplanation->value,
            'Key' => FellowshipImportMappingTarget::CorrectAnswer->value,
            'Level' => FellowshipImportMappingTarget::Difficulty->value,
            'Area' => FellowshipImportMappingTarget::PerformanceTopic->value,
            'Heading' => FellowshipImportMappingTarget::Ignore->value,
        ]);

        $this->assertSame([1 => 'Why A', 2 => 'Why B'], $map->optionExplanationHeaders());

        $fellowship = $this->fellowship();
        $this->confirm($this->stage($fellowship, [$this->row()], $map));

        $this->assertSame(
            ['Correct: first line.', 'Incorrect: an anticoagulant.'],
            $fellowship->questions()->with('options')->sole()->options->pluck('explanation')->all(),
        );
    }

    public function test_the_new_columns_are_never_required(): void
    {
        $map = ImportHeaderMap::fromArray([
            'Stem' => FellowshipImportMappingTarget::QuestionText->value,
            'A' => FellowshipImportMappingTarget::AnswerOption->value,
            'B' => FellowshipImportMappingTarget::AnswerOption->value,
            'Key' => FellowshipImportMappingTarget::CorrectAnswer->value,
            'Level' => FellowshipImportMappingTarget::Difficulty->value,
            'Area' => FellowshipImportMappingTarget::PerformanceTopic->value,
        ]);

        $this->assertTrue($map->isComplete());
        $this->assertFalse($map->has(FellowshipImportMappingTarget::Title));
        $this->assertSame([], $map->optionExplanationHeaders());

        $map->assertUsable();
    }
}
