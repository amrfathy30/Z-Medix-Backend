<?php

namespace App\Console\Commands\Smoke;

use App\Support\Smoke\Fellowship\FellowshipSmokeContext;
use App\Support\Smoke\Fellowship\FellowshipSmokeFixtures;
use App\Support\Smoke\Fellowship\FellowshipSmokeWorkbooks;
use App\Support\Smoke\Fellowship\Scenarios\FellowshipAdminImportScenario;
use App\Support\Smoke\Fellowship\Scenarios\FellowshipAnalyticsScenario;
use App\Support\Smoke\Fellowship\Scenarios\FellowshipIntegrityScenario;
use App\Support\Smoke\Fellowship\Scenarios\FellowshipStudentFlowScenario;
use App\Support\Smoke\Fellowship\Scenarios\FellowshipTimerScenario;
use App\Support\Smoke\SmokeApiClient;
use App\Support\Smoke\SmokeLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Fellowship module, end to end, against the local database.
 *
 * Not a PHPUnit test and deliberately not shaped like one: nothing is rolled
 * back, so every Fellowship, question, import, student and session the run
 * produces stays in MySQL to be inspected afterwards. The workbooks it imports
 * are real .xlsx files written to `storage/app/smoke/fellowship` and left there.
 *
 * What it drives is the application itself. The Fellowship is created on its own
 * Filament page, every question arrives through the real import wizard, and
 * every student action is an authenticated request through the HTTP kernel. No
 * scenario writes a question, a filter, an answer or a study period directly: if
 * the application does not produce it, the run reports that rather than faking
 * it.
 *
 * Unlike a fail-fast smoke run, one broken rule does not end the sweep. Each
 * check is recorded and the run carries on, so the report says how many checks
 * passed, how many failed with what was expected against what happened, and how
 * many were never reached. A scenario whose inputs were never produced reports
 * its checks as skipped rather than failed.
 *
 * Safety, in order of how much it matters:
 *
 *  - it refuses to run outside `local` and `testing`;
 *  - it refuses to run unless the database is the one it was told to expect,
 *    which is the check that stops a misconfigured `.env` pointing it at
 *    staging;
 *  - it never migrates, seeds, truncates or resets anything;
 *  - cleanup removes only records carrying the `FELLOWSHIP_SMOKE_` marker and
 *    the run's own fixed accounts, and the run proves at the end that the number
 *    of rows it does not own has not moved.
 */
class FellowshipModuleSmokeCommand extends Command
{
    protected $signature = 'smoke:fellowship
        {--database=zmedix : The database this run expects to be pointed at}
        {--keep-workbooks : Reuse the workbooks already in storage instead of regenerating them}';

    protected $description = 'Run the Fellowship admin import and student study flows end to end against the local database, leaving the smoke data in place for inspection';

    /** Environments this run may touch. Never production. */
    private const ALLOWED_ENVIRONMENTS = ['local', 'testing'];

    /**
     * Tables the run writes through. Checked before anything is created, so a
     * database behind on migrations is reported as such rather than surfacing as
     * a failure halfway through. The command never migrates.
     *
     * @var list<string>
     */
    private const REQUIRED_TABLES = [
        'admins',
        'users',
        'phone_numbers',
        'email_verification_otps',
        'personal_access_tokens',
        'settings',
        'fellowships',
        'fellowship_filters',
        'fellowship_filter_values',
        'fellowship_questions',
        'fellowship_question_options',
        'fellowship_question_filter_values',
        'fellowship_imports',
        'fellowship_import_rows',
        'fellowship_quiz_sessions',
        'fellowship_session_questions',
        'fellowship_session_periods',
    ];

    public function __construct(
        private readonly FellowshipSmokeFixtures $fixtures,
        private readonly FellowshipSmokeWorkbooks $workbooks,
        private readonly FellowshipAdminImportScenario $adminImport,
        private readonly FellowshipStudentFlowScenario $studentFlow,
        private readonly FellowshipTimerScenario $timer,
        private readonly FellowshipAnalyticsScenario $analytics,
        private readonly FellowshipIntegrityScenario $integrity,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $ledger = new SmokeLedger;
        $context = new FellowshipSmokeContext($ledger, app(SmokeApiClient::class));

        if (! $this->environmentIsSafe($ledger)) {
            return self::FAILURE;
        }

        $this->printHeader();

        $this->prepare($context);
        $this->adminImport->run($context);
        $this->studentFlow->run($context);
        $this->timer->run($context);
        $this->analytics->run($context);
        $this->integrity->run($context);

        $this->verifyNothingForeignChanged($context);

        $this->printLedger($ledger);

        return $ledger->hasFailures() ? self::FAILURE : self::SUCCESS;
    }

    // ── Environment ───────────────────────────────────────────────────────────

    /**
     * Refuse to run anywhere the run has no business writing to.
     *
     * The database name is checked as well as the environment, because an
     * `.env` pointing at a shared database is the mistake that matters here and
     * `APP_ENV=local` would not catch it.
     */
    private function environmentIsSafe(SmokeLedger $ledger): bool
    {
        $environment = app()->environment();

        if (app()->environment('production') || ! app()->environment(self::ALLOWED_ENVIRONMENTS)) {
            $this->components->error(sprintf(
                'Refusing to run in the "%s" environment. This command is for %s only.',
                $environment,
                implode(' and ', self::ALLOWED_ENVIRONMENTS),
            ));

            return false;
        }

        $connection = DB::connection();
        $expected = (string) $this->option('database');
        $actual = (string) $connection->getDatabaseName();

        if ($actual !== $expected) {
            $this->components->error(sprintf(
                'Refusing to run: connected to database "%s" but expected "%s". Pass --database to override deliberately.',
                $actual,
                $expected,
            ));

            return false;
        }

        if ($connection->getDriverName() !== 'mysql') {
            $this->components->error(sprintf(
                'Refusing to run: this scenario is specified against MySQL, but the connection driver is "%s".',
                $connection->getDriverName(),
            ));

            return false;
        }

        $missing = array_values(array_filter(
            self::REQUIRED_TABLES,
            static fn (string $table): bool => ! Schema::hasTable($table),
        ));

        if ($missing !== []) {
            $this->components->error(
                'The database is missing '.implode(', ', $missing)
                    .'. Run `php artisan migrate` and try again; this command does not migrate.',
            );

            return false;
        }

        return true;
    }

    private function printHeader(): void
    {
        $connection = DB::connection();

        $this->newLine();
        $this->line('<options=bold>FELLOWSHIP MODULE SMOKE TEST — Z-MEDIX</>');
        $this->line(sprintf(
            '  Environment: %s   Connection: %s (%s)   Database: %s',
            app()->environment(),
            $connection->getName(),
            $connection->getDriverName(),
            (string) $connection->getDatabaseName(),
        ));

        $version = DB::selectOne('select version() as version, @@sql_mode as sql_mode');

        $this->line('  MySQL: '.($version->version ?? 'unknown'));
        $this->line('  sql_mode: '.($version->sql_mode ?? 'unknown'));
        $this->newLine();
    }

    // ── Preparation ───────────────────────────────────────────────────────────

    /**
     * Clear the previous run, record the baseline, generate the workbooks and
     * resolve the admin the import is driven as.
     */
    private function prepare(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $ledger->group('Preparation');

        $removed = $this->fixtures->cleanPreviousRun();

        $ledger->pass(sprintf(
            'Previous smoke records removed (fellowships: %d, questions: %d, imports: %d, sessions: %d, students: %d, admins: %d)',
            $removed['fellowships'],
            $removed['questions'],
            $removed['imports'],
            $removed['sessions'],
            $removed['students'],
            $removed['admins'],
        ));

        $context->baselineCounts = $this->fixtures->tableCounts();
        $context->baselineForeignCounts = $this->fixtures->foreignRowCounts([], []);

        $ledger->note('Baseline fellowship_questions', $context->baselineCounts['fellowship_questions'] ?? 0);
        $ledger->note('Baseline fellowships', $context->baselineCounts['fellowships'] ?? 0);

        $directory = storage_path('app/'.FellowshipSmokeWorkbooks::DIRECTORY);

        if ($this->option('keep-workbooks') && is_dir($directory) && glob($directory.'/*.xlsx') !== []) {
            $ledger->skip('Workbooks generated', 'Reusing the workbooks already in '.$directory.'.');
        } else {
            $context->workbooks = $this->workbooks->generateAll($directory);

            $ledger->check(
                'Workbooks generated',
                count($context->workbooks) === 8,
                'Expected 8 workbooks, generated '.count($context->workbooks).'.',
            );

            foreach ($context->workbooks as $key => $workbook) {
                $ledger->check(
                    'Workbook '.$key.' written as a real .xlsx',
                    is_file($workbook['path']) && filesize($workbook['path']) > 2048,
                    'Missing or implausibly small: '.$workbook['path'],
                );
            }
        }

        $admin = $this->fixtures->importingAdmin();

        $ledger->check(
            'An admin is available to import as',
            $admin !== null,
            'No admin account exists; run the roles and permissions seeder first.',
        );

        $context->admin = $admin;
        $ledger->note('Importing admin', $admin?->email);

        // A panel account for signing in by hand or in a browser afterwards,
        // so manual verification never needs the installation's own
        // super-admin password. Created here rather than in a scenario because
        // it is useful whether or not any scenario ran.
        $browserAdmin = $this->fixtures->browserAdmin();

        $ledger->check(
            'A browser-verification admin is available',
            $browserAdmin->getAllPermissions()->count() >= 12,
            'The browser admin holds only '.$browserAdmin->getAllPermissions()->count().' permissions.',
        );

        $ledger->note('Browser admin', $browserAdmin->email);
    }

    // ── Closing check ─────────────────────────────────────────────────────────

    /**
     * The run must not have touched a single record it does not own.
     *
     * Counted as "rows whose Fellowship or user is not one of this run's", so a
     * stray write into an existing Fellowship would show up here even though the
     * total row count also moved for legitimate reasons.
     */
    private function verifyNothingForeignChanged(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $ledger->group('Integrity · No foreign writes');

        $after = $this->fixtures->foreignRowCounts($context->fellowshipIds(), $context->userIds());

        foreach ($context->baselineForeignCounts as $table => $before) {
            // The baseline was taken before this run owned anything, so its
            // "foreign" figure counted every pre-existing row — which is exactly
            // the number that must still be there.
            $ledger->same(
                'No pre-existing rows changed in '.$table,
                $before,
                $after[$table] ?? -1,
            );
        }

        $ledger->note('Final counts', json_encode($this->fixtures->tableCounts()));
    }

    // ── Reporting ─────────────────────────────────────────────────────────────

    private function printLedger(SmokeLedger $ledger): void
    {
        $this->newLine();
        $this->line('<options=bold>RESULTS BY SCENARIO GROUP</>');
        $this->newLine();

        foreach ($ledger->byGroup() as $group => $tally) {
            $this->line(sprintf(
                '  %-42s <fg=green>%3d pass</>  %s  %s',
                $group,
                $tally[SmokeLedger::PASS],
                $tally[SmokeLedger::FAIL] > 0
                    ? sprintf('<fg=red>%3d fail</>', $tally[SmokeLedger::FAIL])
                    : '   0 fail',
                $tally[SmokeLedger::SKIP] > 0
                    ? sprintf('<fg=yellow>%3d skip</>', $tally[SmokeLedger::SKIP])
                    : '   0 skip',
            ));
        }

        $failures = $ledger->failures();

        if ($failures !== []) {
            $this->newLine();
            $this->line('<fg=red;options=bold>FAILURES</>');
            $this->newLine();

            foreach ($failures as $index => $failure) {
                $this->line(sprintf('  %2d. [%s] %s', $index + 1, $failure['group'], $failure['label']));
                $this->line('      '.$failure['detail']);

                if ($failure['response'] !== null) {
                    $this->line('      '.$failure['response']);
                }
            }
        }

        $skipped = $ledger->skipped();

        if ($skipped !== []) {
            $this->newLine();
            $this->line('<fg=yellow;options=bold>NOT TESTED</>');
            $this->newLine();

            foreach ($skipped as $entry) {
                $this->line(sprintf('  - [%s] %s — %s', $entry['group'], $entry['label'], $entry['detail']));
            }
        }

        $this->newLine();
        $this->line('<options=bold>RECORD IDS AND COUNTS</>');
        $this->newLine();

        foreach ($ledger->notes() as $label => $value) {
            $this->line(sprintf('  %-34s %s', $label.':', $value ?? 'none'));
        }

        $this->newLine();
        $this->line(sprintf(
            '  TOTAL  <fg=green>%d PASS</>  %s  %s',
            $ledger->countOf(SmokeLedger::PASS),
            $ledger->countOf(SmokeLedger::FAIL) > 0
                ? sprintf('<fg=red>%d FAIL</>', $ledger->countOf(SmokeLedger::FAIL))
                : '0 FAIL',
            $ledger->countOf(SmokeLedger::SKIP) > 0
                ? sprintf('<fg=yellow>%d NOT TESTED</>', $ledger->countOf(SmokeLedger::SKIP))
                : '0 NOT TESTED',
        ));

        $this->newLine();
        $this->line($ledger->hasFailures()
            ? '<fg=red;options=bold>FELLOWSHIP SMOKE TEST FAILED</>'
            : '<fg=green;options=bold>FELLOWSHIP SMOKE TEST PASSED</>');
        $this->line('  Smoke records were left in MySQL for inspection. Marker: '.FellowshipSmokeFixtures::MARKER);
        $this->line('  Workbooks: '.storage_path('app/'.FellowshipSmokeWorkbooks::DIRECTORY));
        $this->newLine();
    }
}
