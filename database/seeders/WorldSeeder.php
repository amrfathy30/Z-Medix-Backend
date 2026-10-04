<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Nnjeim\World\Actions\SeedAction;

/**
 * Seeds the world reference data (countries and the enabled world modules).
 *
 * Student registration validates the submitted country against the `countries`
 * table, so this data must exist in every environment. The seeder is safe to run
 * repeatedly: it is skipped once the table holds data, because the underlying
 * package action truncates and re-inserts, which would reassign the country ids
 * that `users.country_id` and `phone_numbers.country_id` already reference.
 *
 * To deliberately rebuild the data, use the package's own `world:install`
 * command, which is designed for that.
 */
class WorldSeeder extends Seeder
{
    /**
     * The package's seed action aborts the process when the memory limit is
     * below this value.
     */
    private const REQUIRED_MEMORY_LIMIT_BYTES = 512 * 1024 * 1024;

    public function run(): void
    {
        $table = (string) config('world.migrations.countries.table_name', 'countries');
        $schema = Schema::connection(config('world.connection'));

        if (! $schema->hasTable($table)) {
            $this->command?->getOutput()->writeln(
                "<comment>Skipping world data: the [{$table}] table does not exist yet.</comment>"
            );

            return;
        }

        if ($schema->getConnection()->table($table)->exists()) {
            $this->command?->getOutput()->writeln(
                "<info>World data already seeded; leaving [{$table}] untouched.</info>"
            );

            return;
        }

        $this->ensureSufficientMemoryLimit();

        $this->call([
            SeedAction::class,
        ]);
    }

    /**
     * Raise the memory limit for this process if it sits below what the package
     * requires, so a plain `php artisan db:seed` succeeds without the caller
     * having to pass `-d memory_limit=512M`.
     */
    private function ensureSufficientMemoryLimit(): void
    {
        $current = (string) ini_get('memory_limit');

        if ($current === '-1') {
            return;
        }

        if ($this->toBytes($current) >= self::REQUIRED_MEMORY_LIMIT_BYTES) {
            return;
        }

        ini_set('memory_limit', '512M');
    }

    private function toBytes(string $memoryLimit): int
    {
        $value = (int) $memoryLimit;

        return match (mb_strtolower(mb_substr($memoryLimit, -1))) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
