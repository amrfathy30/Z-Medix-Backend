<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The one filter whose values are this Fellowship's Performance Topics.
 *
 * Held here, at Fellowship level, so the classification has a single identity:
 * each import maps some column onto it, whatever that column happens to be
 * called, and every question in the Fellowship therefore carries exactly one
 * value of the same filter. Fellowship cards, Topics Completed, Topic
 * Performance and the session topic summaries all read from it.
 *
 * Nullable only until the first import designates it. Added separately from the
 * `fellowships` table so the two tables are not defined in terms of each other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fellowships', function (Blueprint $table) {
            $table->foreignId('performance_filter_id')
                ->nullable()
                ->after('is_active')
                ->constrained('fellowship_filters')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fellowships', function (Blueprint $table) {
            $table->dropConstrainedForeignId('performance_filter_id');
        });
    }
};
