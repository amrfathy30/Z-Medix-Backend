<?php

namespace Tests\Feature\Fellowship;

use App\Enums\FellowshipImportMappingTarget;
use App\Enums\FellowshipImportRowOutcome;
use App\Enums\FellowshipImportStatus;
use App\Exceptions\Fellowship\FellowshipImportException;
use App\Models\Fellowship;
use App\Models\FellowshipImport;
use App\Services\Fellowship\FellowshipImportConfirmationService;
use App\Services\Fellowship\FellowshipImportStagingService;
use App\Support\Fellowship\ImportHeaderMap;
use App\Support\Smoke\Fellowship\Scenarios\FellowshipImportIntegrityProbeScenario;
use RuntimeException;
use Throwable;

/**
 * What a confirmation that fails after its rows are in leaves behind.
 *
 * The rows are applied one transaction each, so a failure raised once they are
 * committed cannot roll them back — which is the point, since it is what lets a
 * retry resume. What the import record is left saying about itself is therefore
 * the whole of the recovery, and it has to be true: staged, so a later claim can
 * pick it up; carrying the reason; and not stamped as something it is not.
 *
 * The failure is injected on the transition to `completed`, which is the one
 * point the module does not catch per row. That is not a contrived place for one
 * to happen: the write that sets the status is an ordinary `UPDATE` on a row the
 * claim holds a lock on, so a lock wait, a lost connection, or any model hook
 * later added to `FellowshipImport` raises exactly this.
 *
 * {@see FellowshipImportIntegrityProbeScenario}
 * runs the same two claims against real MySQL.
 */
class FellowshipImportFailureRecoveryTest extends FellowshipTestCase
{
    private const ROWS = 3;

    /** @return list<string> */
    private function headers(): array
    {
        return ['Stem', 'A', 'B', 'Key', 'Level', 'Area'];
    }

    private function map(): ImportHeaderMap
    {
        return ImportHeaderMap::fromArray([
            'Stem' => FellowshipImportMappingTarget::QuestionText->value,
            'A' => FellowshipImportMappingTarget::AnswerOption->value,
            'B' => FellowshipImportMappingTarget::AnswerOption->value,
            'Key' => FellowshipImportMappingTarget::CorrectAnswer->value,
            'Level' => FellowshipImportMappingTarget::Difficulty->value,
            'Area' => FellowshipImportMappingTarget::PerformanceTopic->value,
        ]);
    }

    private function stage(Fellowship $fellowship): FellowshipImport
    {
        $rows = array_map(
            static fn (int $index): array => ["Question {$index}?", "Aspirin {$index}", "Warfarin {$index}", "Aspirin {$index}", 'Easy', 'Cardiology'],
            range(1, self::ROWS),
        );

        /** @var FellowshipImport $import */
        $import = $fellowship->imports()->create([
            'status' => FellowshipImportStatus::Draft,
            'original_filename' => 'questions.csv',
            'stored_path' => $this->uploadCsv($this->headers(), $rows),
            'headers' => $this->headers(),
        ]);

        return app(FellowshipImportStagingService::class)->stage($import, $this->map());
    }

    /**
     * Make the completing write fail, once, for real.
     *
     * Consumed on the first throw so the recovery's own write is free to
     * succeed — otherwise the test would be about a database that refuses
     * everything rather than about one write that failed.
     */
    private function failOnceWhenCompleting(FellowshipImport $import, string $message): void
    {
        $fired = false;

        FellowshipImport::updating(function (FellowshipImport $updating) use (&$fired, $import, $message): void {
            if ($fired || $updating->getKey() !== $import->getKey()) {
                return;
            }

            if ($updating->status !== FellowshipImportStatus::Completed) {
                return;
            }

            $fired = true;

            throw new RuntimeException($message);
        });
    }

    /**
     * @return array{import: FellowshipImport, thrown: Throwable|null}
     */
    private function confirmExpectingFailure(FellowshipImport $import, string $message): array
    {
        $this->failOnceWhenCompleting($import, $message);

        $thrown = null;

        try {
            app(FellowshipImportConfirmationService::class)->confirm($import);
        } catch (Throwable $exception) {
            $thrown = $exception;
        }

        return ['import' => $import->refresh(), 'thrown' => $thrown];
    }

    protected function tearDown(): void
    {
        FellowshipImport::flushEventListeners();

        parent::tearDown();
    }

    public function test_a_failed_confirmation_goes_back_to_staged_rather_than_stranding_in_processing(): void
    {
        $fellowship = $this->fellowship();
        $import = $this->stage($fellowship);

        ['import' => $import, 'thrown' => $thrown] = $this->confirmExpectingFailure($import, 'injected failure');

        $this->assertInstanceOf(FellowshipImportException::class, $thrown);
        $this->assertSame(
            FellowshipImportStatus::Staged,
            $import->status,
            'An import left in processing is one no later claim can pick up.',
        );
        $this->assertStringContainsString('injected failure', (string) $import->failure_reason);
    }

    /**
     * The bug this test exists for: `update()` fills before it saves, so a
     * failure raised from the completing save leaves `confirmed_at` dirty on the
     * instance, and the recovery wrote it out alongside the status it was
     * putting back.
     */
    public function test_a_failed_confirmation_is_not_left_stamped_as_confirmed(): void
    {
        $fellowship = $this->fellowship();
        $import = $this->stage($fellowship);

        ['import' => $import] = $this->confirmExpectingFailure($import, 'injected failure');

        $this->assertNull(
            $import->confirmed_at,
            'An import that did not complete must not carry a confirmation timestamp.',
        );
        $this->assertSame(FellowshipImportStatus::Staged, $import->status);
    }

    public function test_the_rows_committed_before_the_failure_keep_their_outcomes(): void
    {
        $fellowship = $this->fellowship();
        $import = $this->stage($fellowship);

        ['import' => $import] = $this->confirmExpectingFailure($import, 'injected failure');

        $this->assertSame(self::ROWS, $import->processedRows()->count());
        $this->assertSame(
            self::ROWS,
            $import->rows()->where('outcome', FellowshipImportRowOutcome::Created)->count(),
        );
        $this->assertSame(0, $import->rows()->importable()->pending()->count());
        $this->assertSame(self::ROWS, $fellowship->questions()->count());
    }

    public function test_retrying_a_failed_confirmation_completes_it_without_inserting_twice(): void
    {
        $fellowship = $this->fellowship();
        $import = $this->stage($fellowship);

        ['import' => $import] = $this->confirmExpectingFailure($import, 'injected failure');

        $import = app(FellowshipImportConfirmationService::class)->confirm($import);

        $this->assertSame(FellowshipImportStatus::Completed, $import->status);
        $this->assertNotNull($import->confirmed_at);
        $this->assertSame(self::ROWS, $import->created_questions);
        $this->assertSame(
            self::ROWS,
            $fellowship->questions()->count(),
            'The retry must resume rather than repeat.',
        );
        $this->assertSame(
            self::ROWS,
            $fellowship->questions()->distinct()->pluck('content_hash')->count(),
            'No two questions may share a content hash.',
        );
    }

    /**
     * A retry must not fork the filters or the values the first attempt
     * created either.
     */
    public function test_retrying_a_failed_confirmation_duplicates_no_filter_value(): void
    {
        $fellowship = $this->fellowship();
        $import = $this->stage($fellowship);

        ['import' => $import] = $this->confirmExpectingFailure($import, 'injected failure');

        $filtersAfterFailure = $fellowship->filters()->count();
        $valuesAfterFailure = $fellowship->performanceFilter?->values()->count();

        app(FellowshipImportConfirmationService::class)->confirm($import);

        $this->assertSame($filtersAfterFailure, $fellowship->refresh()->filters()->count());
        $this->assertSame($valuesAfterFailure, $fellowship->performanceFilter?->values()->count());
        $this->assertSame(
            1,
            $fellowship->performanceFilter?->values()->where('value', 'Cardiology')->count(),
            'The topic value must be reused by the retry, not created a second time.',
        );
    }
}
