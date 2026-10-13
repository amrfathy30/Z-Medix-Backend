<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A question's value for one optional dynamic filter.
 *
 * `fellowship_filter_id` is carried alongside the value it was chosen from, so
 * "one question has at most one value per filter" is a unique index rather than
 * an application convention. A question is never required to have a row here:
 * filters introduced by a later import simply leave earlier questions
 * unclassified.
 *
 * The Performance Topic is not stored here — it is the question's own
 * `performance_filter_value_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fellowship_question_filter_values', function (Blueprint $table) {
            $table->id();
            // The constraints and indexes are named explicitly: this table's
            // name plus these column names exceeds the 64-character limit MySQL
            // puts on an identifier, which Laravel's derived names would hit.
            $table->unsignedBigInteger('fellowship_question_id');
            $table->unsignedBigInteger('fellowship_filter_id');
            $table->unsignedBigInteger('fellowship_filter_value_id');
            $table->timestamps();

            $table->foreign('fellowship_question_id', 'fqfv_question_foreign')
                ->references('id')->on('fellowship_questions')->cascadeOnDelete();
            $table->foreign('fellowship_filter_id', 'fqfv_filter_foreign')
                ->references('id')->on('fellowship_filters')->cascadeOnDelete();
            $table->foreign('fellowship_filter_value_id', 'fqfv_filter_value_foreign')
                ->references('id')->on('fellowship_filter_values')->cascadeOnDelete();

            $table->unique(['fellowship_question_id', 'fellowship_filter_id'], 'fqfv_question_filter_unique');
            $table->index('fellowship_filter_value_id', 'fqfv_filter_value_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fellowship_question_filter_values');
    }
};
