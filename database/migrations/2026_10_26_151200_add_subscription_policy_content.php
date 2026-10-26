<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $page = DB::table('pages')->where('key', 'subscription-policy')->first();
            $pageId = $page?->id ?? DB::table('pages')->insertGetId([
                'key' => 'subscription-policy',
                'label' => 'Subscription Policy',
                'status' => 'published',
                'published_at' => now(),
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (! DB::table('page_sections')->where('page_id', $pageId)->where('section_key', 'content')->exists()) {
                DB::table('page_sections')->insert([
                    'page_id' => $pageId,
                    'section_key' => 'content',
                    'label' => 'Content',
                    'data' => json_encode([
                        'title' => ['en' => 'Subscription Policy', 'ar' => 'سياسة الاشتراك'],
                        'body' => ['en' => '', 'ar' => ''],
                    ], JSON_THROW_ON_ERROR),
                    'status' => 'draft',
                    'sort_order' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Content may have been edited; rollback must preserve admin-authored data.
    }
};
