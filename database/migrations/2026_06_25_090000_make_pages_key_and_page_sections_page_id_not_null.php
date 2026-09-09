<?php

use App\Support\Content\Migrations\PageSchemaPreflightCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3I: closes the last deferred-schema item from Phase 3D/3H by making
 * pages.key and page_sections.page_id NOT NULL. Aborts loudly via
 * PageSchemaPreflightCheck instead of failing on a raw DB constraint
 * violation if any row still has a null key/page_id.
 *
 * page_sections.page_id's foreign key also switches from nullOnDelete to
 * restrictOnDelete — nullifying it on a delete is no longer possible once
 * the column is NOT NULL.
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
                "Cannot make pages.key/page_sections.page_id NOT NULL — preflight checks failed:\n - "
                .implode("\n - ", $violations)
                ."\nResolve the issues above before retrying this migration."
            );
        }

        Schema::table('page_sections', function (Blueprint $table) {
            // Dropped by column rather than by name: SQLite's grammar
            // cannot drop a foreign key by constraint name, only by column.
            $table->dropForeign(['page_id']);
        });

        Schema::table('page_sections', function (Blueprint $table) {
            $table->unsignedBigInteger('page_id')->nullable(false)->change();
            $table->foreign('page_id', 'page_sections_page_id_foreign')
                ->references('id')->on('pages')
                ->restrictOnDelete();
        });

        Schema::table('pages', function (Blueprint $table) {
            $table->string('key')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->string('key')->nullable()->change();
        });

        Schema::table('page_sections', function (Blueprint $table) {
            $table->dropForeign(['page_id']);
        });

        Schema::table('page_sections', function (Blueprint $table) {
            $table->unsignedBigInteger('page_id')->nullable()->change();
            $table->foreign('page_id', 'page_sections_page_id_foreign')
                ->references('id')->on('pages')
                ->nullOnDelete();
        });
    }
};
