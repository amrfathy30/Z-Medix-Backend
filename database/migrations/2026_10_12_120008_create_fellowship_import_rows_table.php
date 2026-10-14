<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One spreadsheet row as staging understood it, and what became of it.
 *
 * `payload` is the row's mapped data, normalized once at staging. Confirmation
 * reads it from here and never re-opens the workbook, which is what lets the
 * admin review, decide, and confirm across separate requests — and what lets a
 * failed confirmation be retried without the uploaded file still being around.
 *
 * `status` records what staging concluded, `errors` why a row was rejected, and
 * `decision` the admin's per-row answer for a duplicate. `outcome` together
 * with `processed_at` is the retry guard: confirmation skips any row already
 * stamped, so re-running it finishes the job instead of repeating it.
 *
 * `duplicate_of_question_id` is nulled rather than cascaded when that question
 * is deleted, so import history survives it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fellowship_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fellowship_import_id')->constrained('fellowship_imports')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('status');
            $table->string('duplicate_scope')->nullable();
            $table->foreignId('duplicate_of_question_id')
                ->nullable()
                ->constrained('fellowship_questions')
                ->nullOnDelete();
            // The earlier row of this same upload that this row duplicates.
            // A row number rather than a foreign key: the twin is identified by
            // its place in the file the admin is looking at.
            $table->unsignedInteger('duplicate_of_row_number')->nullable();
            $table->string('decision')->nullable();
            $table->json('errors')->nullable();
            $table->json('payload');
            $table->string('outcome')->nullable();
            $table->foreignId('fellowship_question_id')
                ->nullable()
                ->constrained('fellowship_questions')
                ->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['fellowship_import_id', 'row_number']);
            $table->index(['fellowship_import_id', 'status']);
            $table->index(['fellowship_import_id', 'processed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fellowship_import_rows');
    }
};
