<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A dedicated single-row table for the Google Analytics (GA4) integration —
 * kept separate from the generic `settings` table so these fields never leak
 * into the Website Settings page's auto-generated group tabs, and so the
 * public analytics endpoint stays independent of the public settings
 * endpoint.
 *
 * Exactly one row, ever: `enabled`/`measurement_id` are always public
 * (read by the frontend to initialise gtag.js), `api_secret`/`property_id`/
 * `service_account_json` are always private (used only for server-side
 * Measurement Protocol calls / GA4 Data API reporting). That split is fixed
 * by which columns a consumer selects, not a per-row flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_analytics_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->string('measurement_id')->nullable();
            $table->string('api_secret')->nullable();
            $table->string('property_id')->nullable();
            $table->text('service_account_json')->nullable();
            $table->timestamps();
        });

        // Exactly one row, ever. insertOrIgnore so re-running against a table
        // that already holds it is a no-op rather than a failure.
        DB::table('google_analytics_settings')->insertOrIgnore([
            'id' => 1,
            'enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('google_analytics_settings');
    }
};
