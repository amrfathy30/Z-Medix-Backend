<?php

use App\Enums\Marketing\MarketingPixelPlatform;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per marketing platform whose tracking pixel the frontend installs.
 *
 * A dedicated table rather than generic `settings` rows, so the public
 * marketing endpoint and `/api/public/settings` stay completely independent
 * of one another. Two INDEPENDENT columns carry two independent decisions:
 * `pixel_id` is what the platform issued, `is_public` is whether the
 * frontend may install it — switching a platform off must never clear its
 * stored ID.
 *
 * The Meta Conversions API columns (`meta_capi_*`) configure server-side
 * event delivery for the Facebook/Meta row only; other platforms leave them
 * at their defaults.
 *
 * The rows are created here (one per MarketingPixelPlatform case) rather
 * than in a seeder, because the admin page only ever UPDATES them — seeding
 * at migration time guarantees the form has something to bind to on a fresh
 * install.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_pixels', function (Blueprint $table): void {
            $table->id();
            $table->string('platform', 32)->unique();
            $table->string('pixel_id', 64)->nullable();
            $table->boolean('is_public')->default(false);
            $table->boolean('meta_capi_enabled')->default(false);
            $table->text('meta_capi_access_token')->nullable();
            $table->string('meta_capi_access_token_last4', 4)->nullable();
            $table->boolean('meta_capi_test_mode')->default(false);
            $table->string('meta_capi_test_event_code')->nullable();
            $table->timestamps();

            $table->index(['is_public', 'platform']);
        });

        $now = now();

        // insertOrIgnore, so re-running against a table that already holds
        // the rows is a no-op rather than a unique-constraint failure.
        DB::table('marketing_pixels')->insertOrIgnore(
            array_map(fn (MarketingPixelPlatform $platform): array => [
                'platform' => $platform->value,
                'pixel_id' => null,
                'is_public' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ], MarketingPixelPlatform::cases()),
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_pixels');
    }
};
