<?php

namespace App\Support\Content\Migrations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Verifies the Website Content schema is safe to finalize — first used by
 * the Phase 3D constraints migration, extended in Phase 3H to additionally
 * guard the legacy-column-drop migration, and reused unchanged by Phase 3I
 * to guard making pages.key/page_sections.page_id NOT NULL (the existing
 * null-key/null-page_id checks already cover that case exactly). Aborts
 * with a clear message
 * instead of failing on a raw DB constraint violation or silently losing
 * data, and is exposed here (rather than inlined in a migration) so it can
 * be tested directly without re-running migrations against bad data.
 *
 * Checks operate across all rows, including soft-deleted ones — a unique
 * index or foreign key is enforced by the database regardless of
 * `deleted_at`, so soft-deleted rows must be clean too.
 *
 * Phase 3H note: "no active app code depends on the legacy columns being
 * dropped" cannot be expressed as a database query — it was verified by
 * auditing every reference to page_sections.page_key/type/is_public/title/
 * subtitle/body and pages.slug/content/meta_title/meta_description/
 * published_at across app/ and database/ before writing the drop migration
 * (see the Phase 3H report for the full file list).
 */
class PageSchemaPreflightCheck
{
    /**
     * @param  list<string>  $requiredPageKeys  Reserved for callers that need to assert a page key is
     *                                          known/expected; not currently used to require existence —
     *                                          a brand-new install with no pages rows must stay clean.
     * @param  list<array{page_key: string, section_key: string}>  $requiredSections  For each pair, if a
     *                                                                                page with that key
     *                                                                                already exists, it
     *                                                                                must have a section
     *                                                                                with that section_key.
     * @return list<string>
     */
    public static function violations(array $requiredPageKeys = [], array $requiredSections = []): array
    {
        return [
            ...self::pageKeyViolations(),
            ...self::pageIdViolations(),
            ...self::legacyContentCopyViolations(),
            ...self::requiredStaticPageSectionViolations($requiredSections),
        ];
    }

    public static function isClean(array $requiredPageKeys = [], array $requiredSections = []): bool
    {
        return self::violations($requiredPageKeys, $requiredSections) === [];
    }

    /** @return list<string> */
    private static function pageKeyViolations(): array
    {
        $violations = [];

        $nullKeyCount = DB::table('pages')->whereNull('key')->count();
        if ($nullKeyCount > 0) {
            $violations[] = "{$nullKeyCount} pages row(s) have a null key.";
        }

        $duplicateKeys = DB::table('pages')
            ->select('key')
            ->whereNotNull('key')
            ->groupBy('key')
            ->havingRaw('count(*) > 1')
            ->pluck('key');
        if ($duplicateKeys->isNotEmpty()) {
            $violations[] = 'Duplicate pages.key value(s): '.$duplicateKeys->implode(', ').'.';
        }

        return $violations;
    }

    /** @return list<string> */
    private static function pageIdViolations(): array
    {
        $violations = [];

        $nullPageIdCount = DB::table('page_sections')->whereNull('page_id')->count();
        if ($nullPageIdCount > 0) {
            $violations[] = "{$nullPageIdCount} page_sections row(s) have a null page_id.";
        }

        $orphanCount = DB::table('page_sections')
            ->whereNotNull('page_id')
            ->whereNotIn('page_id', DB::table('pages')->select('id'))
            ->count();
        if ($orphanCount > 0) {
            $violations[] = "{$orphanCount} page_sections row(s) have a page_id that does not reference an existing pages row.";
        }

        $duplicatePairs = DB::table('page_sections')
            ->select('page_id', 'section_key')
            ->whereNotNull('page_id')
            ->groupBy('page_id', 'section_key')
            ->havingRaw('count(*) > 1')
            ->get();
        if ($duplicatePairs->isNotEmpty()) {
            $violations[] = "{$duplicatePairs->count()} duplicate (page_id, section_key) pair(s) found in page_sections.";
        }

        return $violations;
    }

    /**
     * Phase 3H: page_sections.title/subtitle/body are about to be dropped —
     * every row with legacy content there must already have that content
     * mirrored into `data` before it's safe to drop the columns. Only
     * reachable while migrating a pre-Phase-3H database that still has
     * these columns; on a fresh install the table is empty when this runs.
     *
     * @return list<string>
     */
    private static function legacyContentCopyViolations(): array
    {
        if (! self::hasLegacyContentColumns()) {
            return [];
        }

        $uncopied = DB::table('page_sections')
            ->where(function ($query): void {
                $query->whereNotNull('title')->orWhereNotNull('subtitle')->orWhereNotNull('body');
            })
            ->get(['id', 'title', 'subtitle', 'body', 'data'])
            ->filter(fn ($row): bool => self::hasUncopiedLegacyContent($row));

        if ($uncopied->isNotEmpty()) {
            return ["{$uncopied->count()} page_sections row(s) have legacy title/subtitle/body content not yet copied into data. Copy that content into the data column before dropping these legacy columns."];
        }

        return [];
    }

    private static function hasLegacyContentColumns(): bool
    {
        return Schema::hasColumns('page_sections', ['title', 'subtitle', 'body']);
    }

    private static function hasUncopiedLegacyContent(object $row): bool
    {
        $data = json_decode((string) ($row->data ?? '[]'), true) ?? [];

        foreach (['title', 'subtitle', 'body'] as $field) {
            $legacyValue = json_decode((string) ($row->{$field} ?? 'null'), true);

            if ($legacyValue !== null && ! array_key_exists($field, $data)) {
                return true;
            }
        }

        return false;
    }

    /**
     * If a page with a given key already exists (i.e. this isn't a
     * brand-new install), it must already have the required section, or
     * that content would be lost permanently once legacy columns are
     * dropped. A fresh install with no such pages row yet has nothing to
     * lose — PageSectionSeeder creates both the page and its section from
     * the caller's content definitions afterwards.
     *
     * @param  list<array{page_key: string, section_key: string}>  $requiredSections
     * @return list<string>
     */
    private static function requiredStaticPageSectionViolations(array $requiredSections): array
    {
        $violations = [];

        foreach ($requiredSections as $required) {
            $pageKey = $required['page_key'];
            $sectionKey = $required['section_key'];

            $page = DB::table('pages')->where('key', $pageKey)->first();

            if ($page === null) {
                continue;
            }

            $hasSection = DB::table('page_sections')
                ->where('page_id', $page->id)
                ->where('section_key', $sectionKey)
                ->exists();

            if (! $hasSection) {
                $violations[] = "Page '{$pageKey}' already exists but has no '{$sectionKey}' section — its content would be lost. Migrate its content into a page_sections row before retrying.";
            }
        }

        return $violations;
    }
}
