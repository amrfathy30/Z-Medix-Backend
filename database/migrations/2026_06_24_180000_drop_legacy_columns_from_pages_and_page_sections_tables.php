<?php

use App\Support\Content\Migrations\PageSchemaPreflightCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3H: finalizes the Website Content schema by dropping every legacy
 * column that Phases 3B–3G bridged away from. Aborts loudly via
 * PageSchemaPreflightCheck instead of destroying data if anything hasn't
 * been migrated yet (legacy content not copied into `data`, or
 * privacy-policy/terms-and-conditions not yet migrated to a `content`
 * section).
 *
 * pages.key/page_sections.page_id stay nullable — see the Phase 3H report
 * for why NOT NULL is deferred rather than applied in this migration.
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
                "Cannot drop legacy Website Content columns — preflight checks failed:\n - "
                .implode("\n - ", $violations)
                ."\nResolve the issues above before retrying this migration."
            );
        }

        Schema::table('page_sections', function (Blueprint $table) {
            $table->dropUnique(['page_key', 'section_key']);
            $table->dropColumn(['page_key', 'type', 'is_public', 'title', 'subtitle', 'body']);
        });

        Schema::table('pages', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropIndex(['published_at']);
            $table->dropColumn(['slug', 'title', 'content', 'meta_title', 'meta_description', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('id');
            $table->json('title')->nullable();
            $table->json('content')->nullable();
            $table->json('meta_title')->nullable();
            $table->json('meta_description')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->index('published_at');
        });

        Schema::table('page_sections', function (Blueprint $table) {
            $table->string('page_key')->nullable()->after('id');
            $table->string('type')->default('content');
            $table->boolean('is_public')->default(true);
            $table->json('title')->nullable();
            $table->json('subtitle')->nullable();
            $table->json('body')->nullable();
        });

        // Restoring the old unique indexes is intentionally skipped — the
        // restored slug/page_key columns are nullable/empty after a
        // rollback, so re-adding those uniques could fail immediately on
        // multiple null/empty rows. Re-populate the columns first if a
        // genuine rollback-and-restore is needed.
    }
};
