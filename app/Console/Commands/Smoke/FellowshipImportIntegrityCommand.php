<?php

namespace App\Console\Commands\Smoke;

use App\Support\Smoke\Fellowship\FellowshipSmokeContext;
use App\Support\Smoke\Fellowship\FellowshipSmokeFixtures;
use App\Support\Smoke\Fellowship\Scenarios\FellowshipImportIntegrityProbeScenario;
use App\Support\Smoke\SmokeApiClient;
use App\Support\Smoke\SmokeLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The two import claims that only MySQL can settle, run on their own.
 *
 * Deliberately not part of {@see FellowshipModuleSmokeCommand}: that run is a
 * sweep of the whole module and takes as long as one, while these are two narrow
 * questions a reviewer wants to be able to ask again in seconds. It works in a
 * Fellowship of its own and shares the sweep's marker, so either command's
 * cleanup removes what the other left.
 *
 * Same safety rules as the sweep, for the same reasons: `local` and `testing`
 * only, MySQL only, the expected database only, and no migration, seeder or
 * truncation anywhere. The probe writes only into its own Fellowship, and the
 * run proves at the end that the rows it does not own have not moved.
 *
 * Run it with:
 *
 *     php artisan smoke:fellowship-import-integrity
 *
 * The other way to put the Fellowship module in front of MySQL is
 * `phpunit.mysql.xml`, which runs the ordinary suite there instead of on SQLite.
 * Unlike this command it needs a database of its own, once:
 *
 *     mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS zmedix_testing \
 *         CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
 *     php artisan migrate --database=mysql --no-interaction \
 *         --path=database/migrations
 *     php artisan test --compact -c phpunit.mysql.xml
 *
 * The migrate step wants `DB_DATABASE=zmedix_testing` in the environment for
 * that one command, since it is the test database being built and never the
 * development one. Thereafter `RefreshDatabase` keeps it current by itself.
 */
class FellowshipImportIntegrityCommand extends Command
{
    protected $signature = 'smoke:fellowship-import-integrity
        {--database=zmedix : The database this run expects to be pointed at}';

    protected $description = 'Verify on real MySQL that an update clears an empty mapped filter value, and that a failed confirmation recovers and retries safely';

    /** Environments this run may touch. Never production. */
    private const ALLOWED_ENVIRONMENTS = ['local', 'testing'];

    /** @var list<string> */
    private const REQUIRED_TABLES = [
        'admins',
        'fellowships',
        'fellowship_filters',
        'fellowship_filter_values',
        'fellowship_questions',
        'fellowship_question_options',
        'fellowship_question_filter_values',
        'fellowship_imports',
        'fellowship_import_rows',
    ];

    public function __construct(
        private readonly FellowshipSmokeFixtures $fixtures,
        private readonly FellowshipImportIntegrityProbeScenario $probe,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $ledger = new SmokeLedger;
        $context = new FellowshipSmokeContext($ledger, app(SmokeApiClient::class));

        if (! $this->environmentIsSafe()) {
            return self::FAILURE;
        }

        $this->printHeader();

        // The baseline is taken by the probe itself, once it has removed its own
        // previous records, so that removal is not counted as a foreign write.
        $context->admin = $this->fixtures->importingAdmin();

        if ($context->admin === null) {
            $this->components->error('No admin account exists to import as.');

            return self::FAILURE;
        }

        $ledger->group('Probe · Setup');
        $ledger->note('Importing admin', $context->admin->email);

        $this->probe->run($context);

        $this->verifyNothingForeignChanged($context);
        $this->printLedger($ledger);

        return $ledger->hasFailures() ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Refuse to run anywhere this has no business writing to. The database name
     * is checked as well as the environment, because an `.env` pointing at a
     * shared database is the mistake that matters and `APP_ENV=local` would not
     * catch it.
     */
    private function environmentIsSafe(): bool
    {
        if (app()->environment('production') || ! app()->environment(self::ALLOWED_ENVIRONMENTS)) {
            $this->components->error(sprintf(
                'Refusing to run in the "%s" environment. This command is for %s only.',
                app()->environment(),
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
                'Refusing to run: these claims are specified against MySQL, but the connection driver is "%s".',
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
        $version = DB::selectOne('select version() as version, @@sql_mode as sql_mode');

        $this->newLine();
        $this->line('<options=bold>FELLOWSHIP IMPORT INTEGRITY PROBE — Z-MEDIX</>');
        $this->line(sprintf(
            '  Environment: %s   Driver: %s   Database: %s',
            app()->environment(),
            $connection->getDriverName(),
            (string) $connection->getDatabaseName(),
        ));
        $this->line('  MySQL: '.($version->version ?? 'unknown'));
        $this->line('  sql_mode: '.($version->sql_mode ?? 'unknown'));
        $this->newLine();
    }

    /**
     * The probe must not have touched a single record it does not own.
     */
    private function verifyNothingForeignChanged(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $ledger->group('Probe · No foreign writes');

        $after = $this->fixtures->foreignRowCounts($context->fellowshipIds(), $context->userIds());

        foreach ($context->baselineForeignCounts as $table => $before) {
            $ledger->same('No pre-existing rows changed in '.$table, $before, $after[$table] ?? -1);
        }
    }

    private function printLedger(SmokeLedger $ledger): void
    {
        $this->newLine();
        $this->line('<options=bold>RESULTS BY GROUP</>');
        $this->newLine();

        foreach ($ledger->byGroup() as $group => $tally) {
            $this->line(sprintf(
                '  %-46s <fg=green>%3d pass</>  %s  %s',
                $group,
                $tally[SmokeLedger::PASS],
                $tally[SmokeLedger::FAIL] > 0 ? sprintf('<fg=red>%3d fail</>', $tally[SmokeLedger::FAIL]) : '   0 fail',
                $tally[SmokeLedger::SKIP] > 0 ? sprintf('<fg=yellow>%3d skip</>', $tally[SmokeLedger::SKIP]) : '   0 skip',
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

        foreach ($ledger->notes() as $label => $value) {
            $this->line(sprintf('  %-26s %s', $label.':', $value ?? 'none'));
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
            ? '<fg=red;options=bold>IMPORT INTEGRITY PROBE FAILED</>'
            : '<fg=green;options=bold>IMPORT INTEGRITY PROBE PASSED</>');
        $this->line('  Probe records were left in MySQL for inspection. Marker: '.FellowshipSmokeFixtures::MARKER);
        $this->line('  Workbooks: '.storage_path('app/'.FellowshipImportIntegrityProbeScenario::DIRECTORY));
        $this->newLine();
    }
}
