<?php

namespace App\Console\Commands\Media;

use Illuminate\Console\Command;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/** Realigns legacy public media rows without moving bytes; never runs automatically. */
class AlignMediaPreviewDiskCommand extends Command
{
    protected $signature = 'media:align-preview-disk {--dry-run : Report what would change without writing}';

    protected $description = 'Point existing public media rows at the same-origin admin preview disk';

    public function handle(): int
    {
        $query = Media::query()->where('disk', 'public');
        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info('Nothing to do: no media rows are on the "public" disk.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("{$count} media row(s) would move from \"public\" to \"filament_public\".");

            return self::SUCCESS;
        }

        $query->update(['disk' => 'filament_public']);
        Media::query()->where('conversions_disk', 'public')->update(['conversions_disk' => 'filament_public']);

        $this->info("Moved {$count} media row(s) to the \"filament_public\" disk.");

        return self::SUCCESS;
    }
}
