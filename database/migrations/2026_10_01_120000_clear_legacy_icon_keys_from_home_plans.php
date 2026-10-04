<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * home/plans rows used to store an icon *key* (e.g. "crown"); `icon` is now an
 * uploaded image whose stored value is a file path such as "content/x.png".
 * A leftover key would be served as a broken /storage/<key> URL, so null it.
 * Uploaded paths always contain a "/", keys never do.
 */
return new class extends Migration
{
    public function up(): void
    {
        $pageId = DB::table('pages')->where('key', 'home')->value('id');

        if ($pageId === null) {
            return;
        }

        DB::table('page_sections')
            ->where('page_id', $pageId)
            ->where('section_key', 'plans')
            ->get(['id', 'data'])
            ->each(function (object $section): void {
                $data = json_decode((string) $section->data, true);

                if (! is_array($data) || ! is_array($data['plans'] ?? null)) {
                    return;
                }

                $data['plans'] = array_map(function (mixed $plan): mixed {
                    if (is_array($plan) && is_string($plan['icon'] ?? null) && ! str_contains($plan['icon'], '/')) {
                        $plan['icon'] = null;
                    }

                    return $plan;
                }, $data['plans']);

                DB::table('page_sections')->where('id', $section->id)->update([
                    'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                ]);
            });
    }

    public function down(): void
    {
        // The removed icon keys are not recoverable.
    }
};
