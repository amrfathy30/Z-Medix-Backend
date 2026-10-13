<?php

namespace Tests\Feature\Fellowship;

use App\Enums\FellowshipImportMappingTarget;
use App\Enums\FellowshipImportRowDecision;
use App\Enums\FellowshipImportRowStatus;
use App\Enums\FellowshipImportStatus;
use App\Exceptions\Fellowship\FellowshipImportException;
use App\Filament\Resources\FellowshipResource\Pages\ImportFellowshipQuestions;
use App\Models\Fellowship;
use App\Models\FellowshipFilterValue;
use App\Models\FellowshipImport;
use App\Models\FellowshipImportRow;
use App\Services\Fellowship\FellowshipFilterService;
use App\Services\Fellowship\FellowshipImportConfirmationService;
use App\Services\Fellowship\FellowshipImportStagingService;
use App\Support\Fellowship\ImportHeaderMap;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * How long a filter value an import may carry.
 *
 * `fellowship_filter_values.value` is a `varchar(255)`, and nothing used to cap
 * it. An over-long cell therefore passed staging and was refused by MySQL at
 * confirmation with error 1406 — caught per row, so nothing was written and the
 * rest of the file still went in, but the admin learned about it only at the end
 * and only as "this row could not be saved". The limit belongs where the file is
 * still on screen and the column can be named.
 *
 * The boundary is in **characters**, not bytes, because the column is `utf8mb4`
 * and that is what MySQL counts there. 255 Arabic characters occupy 510 bytes
 * and are perfectly valid, which is why one of these tests is in Arabic: a
 * `strlen()` regression would refuse half of everything this platform carries
 * and every ASCII test here would still pass.
 */
class FellowshipImportFilterValueLengthTest extends FellowshipTestCase
{
    private const LIMIT = FellowshipFilterValue::VALUE_MAX_LENGTH;

    /** @return list<string> */
    private function headers(): array
    {
        return ['Stem', 'A', 'B', 'Key', 'Level', 'Area', 'System'];
    }

    /**
     * `Area` feeds the Performance Topic; `System` is an optional dynamic filter
     * this import would create.
     *
     * @param  array<string, array<string, mixed>|string>  $overrides
     */
    private function map(array $overrides = []): ImportHeaderMap
    {
        return ImportHeaderMap::fromArray([
            'Stem' => FellowshipImportMappingTarget::QuestionText->value,
            'A' => FellowshipImportMappingTarget::AnswerOption->value,
            'B' => FellowshipImportMappingTarget::AnswerOption->value,
            'Key' => FellowshipImportMappingTarget::CorrectAnswer->value,
            'Level' => FellowshipImportMappingTarget::Difficulty->value,
            'Area' => FellowshipImportMappingTarget::PerformanceTopic->value,
            'System' => ['target' => FellowshipImportMappingTarget::NewFilter->value, 'filter_name' => 'System'],
            ...$overrides,
        ]);
    }

    /** @return list<string|null> */
    private function row(
        string $stem = 'Which antiplatelet?',
        string $area = 'Cardiology',
        ?string $system = 'Cardiovascular',
    ): array {
        return [$stem, 'Aspirin', 'Warfarin', 'Aspirin', 'Easy', $area, $system];
    }

    /**
     * @param  list<list<string|null>>  $rows
     */
    private function stage(Fellowship $fellowship, array $rows, ?ImportHeaderMap $map = null): FellowshipImport
    {
        $filename = Str::uuid().'.csv';

        /** @var FellowshipImport $import */
        $import = $fellowship->imports()->create([
            'status' => FellowshipImportStatus::Draft,
            'original_filename' => $filename,
            'stored_path' => $this->uploadCsv($this->headers(), $rows, $filename),
            'headers' => $this->headers(),
        ]);

        return app(FellowshipImportStagingService::class)->stage($import, $map ?? $this->map());
    }

    private function text(int $length, string $character = 'a'): string
    {
        return str_repeat($character, $length);
    }

    /**
     * Rows of `fellowship_filter_values` across the whole database, so a test
     * can say that nothing was written anywhere rather than only in the filter
     * it was looking at.
     */
    private function filterValueCount(): int
    {
        return (int) DB::table('fellowship_filter_values')->count();
    }

    // ── The boundary ──────────────────────────────────────────────────────────

    public function test_a_filter_value_of_exactly_the_limit_is_accepted(): void
    {
        $fellowship = $this->fellowship();

        $import = $this->stage($fellowship, [$this->row(system: $this->text(self::LIMIT))]);

        $this->assertSame(1, $import->valid_rows);
        $this->assertSame(0, $import->invalid_rows);

        app(FellowshipImportConfirmationService::class)->confirm($import);

        $this->assertSame(1, $fellowship->questions()->count());
        $this->assertSame(
            self::LIMIT,
            mb_strlen((string) $fellowship->filters()->where('name', 'System')->sole()->values()->sole()->value),
            'A value of exactly the column width must be stored whole, not truncated.',
        );
    }

    public function test_a_filter_value_one_character_over_the_limit_is_rejected(): void
    {
        $fellowship = $this->fellowship();
        $before = $this->filterValueCount();

        $import = $this->stage($fellowship, [$this->row(system: $this->text(self::LIMIT + 1))]);

        $this->assertSame(0, $import->valid_rows);
        $this->assertSame(1, $import->invalid_rows);
        $this->assertSame(
            FellowshipImportRowStatus::Invalid,
            $import->rows()->sole()->status,
        );
        $this->assertSame($before, $this->filterValueCount(), 'Staging must write no filter value.');
    }

    /**
     * The limit is characters, not bytes. 255 Arabic characters are 510 bytes
     * and fit the column; a `strlen()` check would refuse them.
     */
    public function test_the_limit_counts_characters_and_not_bytes(): void
    {
        $fellowship = $this->fellowship();
        $arabic = $this->text(self::LIMIT, 'ب');

        $this->assertSame(self::LIMIT, mb_strlen($arabic));
        $this->assertGreaterThan(self::LIMIT, strlen($arabic));

        $import = $this->stage($fellowship, [$this->row(system: $arabic)]);

        $this->assertSame(1, $import->valid_rows, 'An Arabic value within the character limit must be accepted.');
        $this->assertSame(0, $import->invalid_rows);
    }

    public function test_an_arabic_filter_value_over_the_limit_is_still_rejected(): void
    {
        $fellowship = $this->fellowship();

        $import = $this->stage($fellowship, [$this->row(system: $this->text(self::LIMIT + 1, 'ب'))]);

        $this->assertSame(1, $import->invalid_rows);
    }

    // ── Which values are covered ──────────────────────────────────────────────

    public function test_an_over_long_performance_topic_is_rejected_and_names_its_column(): void
    {
        $fellowship = $this->fellowship();
        $before = $this->filterValueCount();

        $import = $this->stage($fellowship, [$this->row(area: $this->text(self::LIMIT + 1))]);

        $this->assertSame(1, $import->invalid_rows);

        $errors = $import->rows()->sole()->errors ?? [];

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Area', implode(' ', $errors), 'The reason must name the column to shorten.');
        $this->assertSame($before, $this->filterValueCount());
    }

    public function test_an_over_long_optional_dynamic_filter_value_is_rejected_and_names_its_filter(): void
    {
        $fellowship = $this->fellowship();

        $import = $this->stage($fellowship, [$this->row(system: $this->text(self::LIMIT + 1))]);

        $errors = $import->rows()->sole()->errors ?? [];

        $this->assertStringContainsString('System', implode(' ', $errors));
        $this->assertStringContainsString((string) self::LIMIT, implode(' ', $errors), 'The reason should say what the maximum is.');
    }

    /**
     * A column mapped onto a filter the Fellowship already has is the same
     * column in the same table, so it is checked the same way.
     */
    public function test_an_over_long_value_for_an_existing_filter_is_rejected(): void
    {
        $fellowship = $this->fellowship();
        $system = $this->dynamicFilter($fellowship, 'System', ['Cardiovascular']);
        $valuesBefore = $system->values()->count();

        $import = $this->stage(
            $fellowship,
            [$this->row(system: $this->text(self::LIMIT + 1))],
            $this->map(['System' => [
                'target' => FellowshipImportMappingTarget::ExistingFilter->value,
                'filter_id' => $system->getKey(),
            ]]),
        );

        $this->assertSame(1, $import->invalid_rows);
        $this->assertSame($valuesBefore, $system->refresh()->values()->count());
    }

    public function test_every_over_long_value_in_one_row_is_reported(): void
    {
        $fellowship = $this->fellowship();

        $import = $this->stage($fellowship, [$this->row(
            area: $this->text(self::LIMIT + 1),
            system: $this->text(self::LIMIT + 5),
        )]);

        $errors = $import->rows()->sole()->errors ?? [];
        $joined = implode(' ', $errors);

        $this->assertStringContainsString('Area', $joined);
        $this->assertStringContainsString('System', $joined);
        $this->assertSame(
            1,
            $import->invalid_rows,
            'Two bad values are two reasons on one row, not two rows.',
        );
    }

    // ── Whitespace at the boundary ────────────────────────────────────────────

    /**
     * The stored value is the trimmed cell, so padding that trims back under the
     * limit is valid — and the value stored is the trimmed one.
     */
    public function test_padding_that_trims_back_within_the_limit_is_accepted(): void
    {
        $fellowship = $this->fellowship();
        $padded = '   '.$this->text(self::LIMIT).'   ';

        $this->assertGreaterThan(self::LIMIT, mb_strlen($padded));

        $import = $this->stage($fellowship, [$this->row(system: $padded)]);

        $this->assertSame(1, $import->valid_rows);
        $this->assertSame(0, $import->invalid_rows);

        app(FellowshipImportConfirmationService::class)->confirm($import);

        $stored = (string) $fellowship->filters()->where('name', 'System')->sole()->values()->sole()->value;

        $this->assertSame(self::LIMIT, mb_strlen($stored));
        $this->assertSame(trim($padded), $stored);
    }

    /**
     * Internal whitespace is *not* collapsed on the way into the column — that
     * collapsing decides which existing value a cell matches, never what gets
     * written — so a cell only under the limit once collapsed is still too long.
     */
    public function test_internal_whitespace_does_not_buy_room_under_the_limit(): void
    {
        $fellowship = $this->fellowship();
        $spaced = $this->text(self::LIMIT - 10).str_repeat(' ', 20).'tail';

        $this->assertGreaterThan(self::LIMIT, mb_strlen(trim($spaced)));

        $import = $this->stage($fellowship, [$this->row(system: $spaced)]);

        $this->assertSame(1, $import->invalid_rows);
    }

    // ── An empty optional cell is unchanged ───────────────────────────────────

    public function test_an_empty_optional_filter_cell_is_still_valid(): void
    {
        $fellowship = $this->fellowship();

        $import = $this->stage($fellowship, [$this->row(system: '')]);

        $this->assertSame(1, $import->valid_rows);
        $this->assertSame(0, $import->invalid_rows);
    }

    /**
     * And still clears, which is what the length check must not have disturbed.
     */
    public function test_an_empty_optional_filter_cell_still_clears_an_existing_value(): void
    {
        $fellowship = $this->fellowship();
        $existing = $this->question($fellowship, text: 'Which antiplatelet?');
        $system = $this->dynamicFilter($fellowship, 'System', ['Cardiovascular']);
        $this->tagQuestion($existing, $system, 'Cardiovascular');

        $import = $this->stage(
            $fellowship,
            [$this->row(system: '')],
            $this->map(['System' => [
                'target' => FellowshipImportMappingTarget::ExistingFilter->value,
                'filter_id' => $system->getKey(),
            ]]),
        );

        app(FellowshipImportConfirmationService::class)
            ->decide($import->duplicateRows()->sole(), FellowshipImportRowDecision::UpdateExisting);

        app(FellowshipImportConfirmationService::class)->confirm($import->refresh());

        $this->assertNull($existing->refresh()->valueForFilter($system->getKey()));
    }

    // ── The rest of the file still goes in ────────────────────────────────────

    public function test_valid_rows_beside_an_invalid_one_are_still_imported(): void
    {
        $fellowship = $this->fellowship();

        $import = $this->stage($fellowship, [
            $this->row('First?'),
            $this->row('Second?', system: $this->text(self::LIMIT + 1)),
            $this->row('Third?', area: $this->text(self::LIMIT + 1)),
            $this->row('Fourth?'),
        ]);

        $this->assertSame(2, $import->valid_rows);
        $this->assertSame(2, $import->invalid_rows);

        $import = app(FellowshipImportConfirmationService::class)->confirm($import);

        $this->assertSame(2, $import->created_questions);
        $this->assertSame(
            ['First?', 'Fourth?'],
            $fellowship->questions()->orderBy('id')->pluck('question_text')->all(),
        );

        // Nothing of the rejected rows reached the question bank or the filters.
        // Measured in PHP rather than in SQL, since the length function differs
        // by engine and this suite runs on SQLite.
        $this->assertSame(
            [],
            DB::table('fellowship_filter_values')
                ->pluck('value')
                ->filter(fn (?string $value): bool => mb_strlen((string) $value) > self::LIMIT)
                ->values()
                ->all(),
            'No stored filter value may exceed the column width.',
        );
    }

    /**
     * An invalid row is not importable, so confirmation never considers it —
     * which is what keeps the guard in the writer from ever being the thing
     * that stops it.
     */
    public function test_an_invalid_row_never_reaches_confirmation(): void
    {
        $fellowship = $this->fellowship();

        $import = $this->stage($fellowship, [$this->row(system: $this->text(self::LIMIT + 1))]);

        $this->assertSame(0, $import->rows()->importable()->count());

        $import = app(FellowshipImportConfirmationService::class)->confirm($import);

        $this->assertSame(FellowshipImportStatus::Completed, $import->status);
        $this->assertSame(0, $fellowship->questions()->count());
        $this->assertNull($import->rows()->sole()->processed_at, 'An invalid row is not applied at all.');
    }

    // ── The backstop ──────────────────────────────────────────────────────────

    /**
     * The one place a value row is created refuses an over-long one, so no
     * caller that bypasses staging can reach MySQL's own refusal.
     */
    public function test_creating_an_over_long_value_directly_is_refused(): void
    {
        $fellowship = $this->fellowship();
        $system = $this->dynamicFilter($fellowship, 'System', ['Cardiovascular']);

        $this->expectException(FellowshipImportException::class);

        app(FellowshipFilterService::class)->resolveValue($system, $this->text(self::LIMIT + 1));
    }

    public function test_the_backstop_leaves_a_matching_existing_value_alone(): void
    {
        $fellowship = $this->fellowship();
        $system = $this->dynamicFilter($fellowship, 'System', ['Cardiovascular']);

        $resolved = app(FellowshipFilterService::class)->resolveValue($system, '  CARDIOvascular  ');

        $this->assertSame('Cardiovascular', $resolved->value);
        $this->assertSame(1, $system->refresh()->values()->count());
    }

    /**
     * @return list<array{0: int, 1: bool}>
     */
    public static function boundaries(): array
    {
        return [
            'well under' => [10, true],
            'one under' => [self::LIMIT - 1, true],
            'exactly the limit' => [self::LIMIT, true],
            'one over' => [self::LIMIT + 1, false],
            'well over' => [self::LIMIT * 2, false],
        ];
    }

    #[DataProvider('boundaries')]
    public function test_the_boundary_holds_either_side(int $length, bool $expectedValid): void
    {
        $fellowship = $this->fellowship();

        $import = $this->stage($fellowship, [$this->row(system: $this->text($length))]);

        $this->assertSame($expectedValid ? 1 : 0, $import->valid_rows, "A {$length}-character value.");
        $this->assertSame($expectedValid ? 0 : 1, $import->invalid_rows, "A {$length}-character value.");
    }

    // ── The review screen ─────────────────────────────────────────────────────

    /**
     * The reason has to reach the admin, which means the review screen's
     * rejected-rows table and not only the row's own column.
     */
    public function test_the_reason_is_shown_on_the_import_review_screen(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');

        $fellowship = $this->fellowship();
        $import = $this->stage($fellowship, [
            $this->row('Good one?'),
            $this->row('Bad one?', system: $this->text(self::LIMIT + 1)),
        ]);

        Livewire::test(ImportFellowshipQuestions::class, [
            'record' => $fellowship->getKey(),
            'import' => $import->getKey(),
        ])
            ->assertSet('step', ImportFellowshipQuestions::STEP_REVIEW)
            ->assertSee(__('admin.fellowship.import_error_filter_value_too_long', [
                'filter' => 'System',
                'length' => self::LIMIT + 1,
                'max' => self::LIMIT,
            ]))
            ->assertSee('Bad one?');
    }

    /**
     * The message exists in both locales, since the panel's own default here is
     * Arabic and a missing key would render as a raw key to half the admins.
     */
    public function test_the_message_is_translated_in_both_locales(): void
    {
        $key = 'admin.fellowship.import_error_filter_value_too_long';

        foreach (['en', 'ar'] as $locale) {
            $message = trans($key, ['filter' => 'System', 'length' => 300, 'max' => self::LIMIT], $locale);

            $this->assertNotSame($key, $message, "Missing the {$locale} translation.");
            $this->assertStringContainsString('System', $message);
            $this->assertStringContainsString('300', $message);
            $this->assertStringContainsString((string) self::LIMIT, $message);
        }
    }

    /**
     * The row's reasons are stored as plain strings, so a localized message must
     * survive the round trip through the row's JSON column.
     */
    public function test_the_reason_round_trips_through_the_row(): void
    {
        $fellowship = $this->fellowship();

        $import = $this->stage($fellowship, [$this->row(system: $this->text(self::LIMIT + 1, 'ب'))]);

        /** @var FellowshipImportRow $row */
        $row = $import->rows()->sole();

        $this->assertIsArray($row->errors);
        $this->assertNotEmpty($row->errors);
        $this->assertSame($row->errors, $row->refresh()->errors);
    }
}
