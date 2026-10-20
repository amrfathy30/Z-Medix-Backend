<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The WhatsApp number students are sent to in order to pay for a plan.
 *
 * Added by migration, not only by CmsSeeder, because deployments do not reseed
 * the CMS: existing environments need the row to exist so the admin can fill it
 * in from Settings. It never overwrites a value that is already there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('settings')->where('key', 'whatsapp_number')->exists()) {
            return;
        }

        DB::table('settings')->insert([
            'key' => 'whatsapp_number',
            'value' => '',
            'value_type' => 'string',
            'group' => 'contact',
            'is_public' => true,
            'description' => 'WhatsApp number students contact to pay for a plan (international format)',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'whatsapp_number')->delete();
    }
};
