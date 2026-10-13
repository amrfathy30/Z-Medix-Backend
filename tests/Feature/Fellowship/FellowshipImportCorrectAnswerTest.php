<?php

namespace Tests\Feature\Fellowship;

use App\Enums\FellowshipImportMappingTarget;
use App\Services\Fellowship\FellowshipImportValidator;
use App\Support\Fellowship\ImportHeaderMap;
use App\Support\Fellowship\ImportRowPayload;
use Tests\TestCase;

/**
 * How a spreadsheet says which answer is the right one.
 *
 * The real import file answers with a letter — A, B, C, D — against four
 * option columns, which is how most question banks are written and what the
 * importer originally could not read: it only compared the cell against the
 * option *texts*, so every row of a ninety-row file was rejected as "correct
 * answer does not match any of this row's answer options".
 *
 * Both readings are supported now, and the awkward cases are what this pins
 * down: a letter pointing at a column this row left blank, a letter past the
 * end of the mapped columns, and a cell that could be read two ways at once.
 */
class FellowshipImportCorrectAnswerTest extends TestCase
{
    /**
     * @param  list<string>  $options
     */
    private function payload(array $options, ?string $correctAnswer): ImportRowPayload
    {
        return ImportRowPayload::make(
            questionText: 'Which antiplatelet?',
            explanation: null,
            difficulty: 'Easy',
            correctAnswer: $correctAnswer,
            performanceTopic: 'Cardiology',
            options: $options,
            filters: [],
        );
    }

    /**
     * @return list<string>
     */
    private function fourOptions(): array
    {
        return ['Aspirin', 'Warfarin', 'Clopidogrel', 'Heparin'];
    }

    /**
     * The exact shape of the smoke-test file: four option columns and a letter.
     */
    public function test_an_option_letter_names_the_column_it_addresses(): void
    {
        foreach (['A' => 0, 'B' => 1, 'C' => 2, 'D' => 3] as $letter => $expected) {
            $this->assertSame(
                $expected,
                $this->payload($this->fourOptions(), $letter)->correctOptionIndex(),
                "The letter [{$letter}] must address option ".($expected + 1).'.',
            );
        }
    }

    public function test_an_option_letter_is_read_whatever_its_case_or_punctuation(): void
    {
        foreach (['b', ' B ', 'B.', 'B)', '(b)', "\tb\t"] as $answer) {
            $this->assertSame(
                1,
                $this->payload($this->fourOptions(), $answer)->correctOptionIndex(),
                'The answer ['.trim($answer).'] must address the second option.',
            );
        }
    }

    public function test_a_numbered_answer_names_the_column_it_addresses(): void
    {
        $this->assertSame(0, $this->payload($this->fourOptions(), '1')->correctOptionIndex());
        $this->assertSame(2, $this->payload($this->fourOptions(), '3')->correctOptionIndex());
        $this->assertSame(3, $this->payload($this->fourOptions(), '4.')->correctOptionIndex());
    }

    public function test_the_answer_text_still_names_its_own_option(): void
    {
        $this->assertSame(2, $this->payload($this->fourOptions(), 'Clopidogrel')->correctOptionIndex());
        $this->assertSame(2, $this->payload($this->fourOptions(), '  clopidogrel ')->correctOptionIndex());
    }

    /**
     * A letter addresses the column the admin mapped, not the options that
     * happened to survive this row's blanks: with the second column empty, "C"
     * is still the third column — which is the second option written.
     */
    public function test_a_letter_counts_mapped_columns_not_surviving_options(): void
    {
        $payload = $this->payload(['Aspirin', '', 'Clopidogrel', ''], 'C');

        $this->assertSame(['Aspirin', 'Clopidogrel'], $payload->nonEmptyOptions());
        $this->assertSame(1, $payload->correctOptionIndex(), '"C" is the third column, written second.');
    }

    public function test_a_letter_pointing_at_a_blank_column_names_no_answer(): void
    {
        $this->assertNull(
            $this->payload(['Aspirin', 'Warfarin', '', ''], 'C')->correctOptionIndex(),
            'A column this row left blank is not an answer.',
        );
    }

    public function test_a_letter_past_the_mapped_columns_names_no_answer(): void
    {
        $this->assertNull($this->payload(['Aspirin', 'Warfarin'], 'D')->correctOptionIndex());
        $this->assertNull($this->payload($this->fourOptions(), 'E')->correctOptionIndex());
        $this->assertNull($this->payload($this->fourOptions(), '7')->correctOptionIndex());
    }

    public function test_an_unrecognisable_answer_names_no_answer(): void
    {
        $this->assertNull($this->payload($this->fourOptions(), 'Ibuprofen')->correctOptionIndex());
        $this->assertNull($this->payload($this->fourOptions(), 'AB')->correctOptionIndex());
        $this->assertNull($this->payload($this->fourOptions(), '0')->correctOptionIndex());
    }

    public function test_a_missing_answer_names_no_answer(): void
    {
        $this->assertNull($this->payload($this->fourOptions(), null)->correctOptionIndex());
        $this->assertNull($this->payload($this->fourOptions(), '   ')->correctOptionIndex());
    }

    /**
     * Where the two readings agree there is nothing to choose between them.
     */
    public function test_a_letter_that_is_also_its_own_options_text_is_not_ambiguous(): void
    {
        $this->assertSame(0, $this->payload(['A', 'B', 'C'], 'A')->correctOptionIndex());
        $this->assertSame(1, $this->payload(['A', 'B', 'C'], 'B')->correctOptionIndex());
    }

    /**
     * Where they disagree, neither is used: an option whose text is literally
     * "A" sitting second could be meant either way, and guessing would mark the
     * wrong answer correct.
     */
    public function test_an_answer_that_reads_two_ways_is_refused(): void
    {
        $this->assertNull(
            $this->payload(['B', 'A', 'C'], 'A')->correctOptionIndex(),
            'Text says the second option, the letter says the first.',
        );
    }

    /**
     * Two identical option texts make a text match meaningless. The row is
     * rejected for the duplication in its own right; the answer resolves to
     * nothing rather than to the first of the two.
     */
    public function test_an_answer_naming_two_identical_options_is_refused(): void
    {
        $this->assertNull($this->payload(['Aspirin', 'Aspirin', 'Heparin'], 'Aspirin')->correctOptionIndex());
    }

    /**
     * Exactly one correct option, whichever way the answer was expressed.
     */
    public function test_a_resolved_answer_marks_exactly_one_option(): void
    {
        $payload = $this->payload($this->fourOptions(), 'C');
        $correct = $payload->correctOptionIndex();

        $marked = array_filter(
            array_keys($payload->nonEmptyOptions()),
            static fn (int $index): bool => $index === $correct,
        );

        $this->assertCount(1, $marked);
    }

    /**
     * Any number of mapped option columns, not four: the slots are whatever the
     * admin mapped.
     */
    public function test_letters_work_for_any_number_of_mapped_columns(): void
    {
        $two = $this->payload(['True', 'False'], 'B');
        $six = $this->payload(['a1', 'a2', 'a3', 'a4', 'a5', 'a6'], 'F');

        $this->assertSame(1, $two->correctOptionIndex());
        $this->assertSame(5, $six->correctOptionIndex());
    }

    /**
     * The validator's verdict, not just the index: a letter answer is a valid
     * row, and an unreadable one is reported as the problem it is.
     */
    public function test_the_validator_accepts_a_letter_and_still_rejects_nonsense(): void
    {
        $map = ImportHeaderMap::fromArray([
            'Question' => FellowshipImportMappingTarget::QuestionText->value,
            'Option A' => FellowshipImportMappingTarget::AnswerOption->value,
            'Option B' => FellowshipImportMappingTarget::AnswerOption->value,
            'Correct Answer' => FellowshipImportMappingTarget::CorrectAnswer->value,
            'Difficulty' => FellowshipImportMappingTarget::Difficulty->value,
            'System' => FellowshipImportMappingTarget::PerformanceTopic->value,
        ]);

        $validator = app(FellowshipImportValidator::class);

        $this->assertSame([], $validator->validate($this->payload(['Aspirin', 'Warfarin'], 'B'), $map));
        $this->assertNotSame([], $validator->validate($this->payload(['Aspirin', 'Warfarin'], 'Z'), $map));
    }
}
