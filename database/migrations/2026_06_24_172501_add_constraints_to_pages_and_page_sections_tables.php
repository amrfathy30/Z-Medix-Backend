<?php

use App\Support\Content\Migrations\PageSchemaPreflightCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3D: adds the constraints that depend on Phase 3C's backfill being
 * complete and clean. Aborts loudly via PageSchemaPreflightCheck instead of
 * failing on a raw DB constraint violation if the backfill wasn't run or
 * left unresolved rows.
 *
 * Deliberately additive/constraint-only:
 * - page_sections.page_id and pages.key stay nullable (see Phase 3D report
 *   for why making them NOT NULL is deferred to a later cleanup phase).
 * - The legacy unique(page_key, section_key) index is left in place.
 * - No columns are dropped, no data is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $violations = PageSchemaPreflightCheck::violations(
            requiredPageKeys: ['privacy-policy', 'terms-and-conditions'],
            requiredSections: [
                ['page_key' => 'privacy-policy', 'section_key' => 'content'],
                ['page_key' => 'terms-and-conditions', 'section_key' => 'content'],
            ],
        );

        if ($violations !== []) {
            throw new RuntimeException(
                "Cannot add Website Content schema constraints — preflight checks failed:\n - "
                .implode("\n - ", $violations)
                ."\nResolve the reported rows before retrying this migration."
            );
        }

        Schema::table('pages', function (Blueprint $table) {
            $table->unique('key', 'pages_key_unique');
        });

        Schema::table('page_sections', function (Blueprint $table) {
            $table->unique(['page_id', 'section_key'], 'page_sections_page_id_section_key_unique');
            $table->foreign('page_id', 'page_sections_page_id_foreign')
                ->references('id')->on('pages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('page_sections', function (Blueprint $table) {
            // Dropped by column rather than by name: SQLite's grammar
            // cannot drop a foreign key by constraint name, only by column.
            $table->dropForeign(['page_id']);
            $table->dropUnique('page_sections_page_id_section_key_unique');
        });

        Schema::table('pages', function (Blueprint $table) {
            $table->dropUnique('pages_key_unique');
        });
    }
};
