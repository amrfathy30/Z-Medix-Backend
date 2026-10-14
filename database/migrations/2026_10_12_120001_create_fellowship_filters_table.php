<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A dynamic classification belonging to one Fellowship.
 *
 * Filters are discovered from the spreadsheets an admin uploads rather than
 * declared up front, and they are scoped per Fellowship: two Fellowships may
 * both have a "System" filter and they are two unrelated filters.
 *
 * `name` is the filter's stable identity. The Excel header that feeds it may be
 * spelled differently in the next upload — the wizard maps that header onto
 * this row, so a rename in the file never forks the filter. A filter that
 * stops appearing in later files is simply left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fellowship_filters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fellowship_id')->constrained('fellowships')->cascadeOnDelete();
            $table->string('name');
            // The Fellowship's single Performance Topic filter is pointed at by
            // `fellowships.performance_filter_id`; this flag is the same fact
            // from this side, so filter listings do not have to join back.
            $table->boolean('is_performance')->default(false);
            $table->timestamps();

            $table->unique(['fellowship_id', 'name']);
            $table->index(['fellowship_id', 'is_performance']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fellowship_filters');
    }
};
