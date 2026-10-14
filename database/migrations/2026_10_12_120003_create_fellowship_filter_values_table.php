<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One allowed value of one Fellowship filter, e.g. "Cardiology" of
 * "Performance Topic".
 *
 * Values are reused across imports: a later file naming a value that already
 * exists maps onto this row instead of creating a second one, which is what
 * keeps Topic Performance from splitting a topic in two. New values are only
 * written when an import is confirmed, never while it is being previewed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fellowship_filter_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fellowship_filter_id')->constrained('fellowship_filters')->cascadeOnDelete();
            $table->string('value');
            $table->timestamps();

            $table->unique(['fellowship_filter_id', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fellowship_filter_values');
    }
};
