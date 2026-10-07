<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The questions of one attempt, in the order that attempt will always present
 * them, each frozen as it stood when the attempt was created.
 *
 * `position` is the shuffled sequence written at creation, so leaving the quiz
 * and coming back shows the same questions in the same order.
 *
 * `question_snapshot` is the attempt's source of truth: the question text, the
 * options, which one is correct. The attempt is rendered and graded from it, so
 * an admin may reword, re-option or delete the live question afterwards without
 * altering history. `question_id` survives only as traceability back to the
 * question it came from, and is nulled rather than cascaded when that question
 * goes — nothing here is restricted, because the snapshot already protects the
 * record.
 *
 * The student's answer is stored as the snapshot's own option key, not as a
 * foreign key into `question_options`, so deleting an option can neither
 * destroy nor blank out an answer already given.
 *
 * An answer is final: `selected_option_key`, `is_correct` and `answered_at` are
 * written once and never updated again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_attempt_questions', function (Blueprint $table) {
            $table->id();
            // The attempt owns its question rows, so they go when it goes.
            $table->foreignId('quiz_attempt_id')->constrained('quiz_attempts')->cascadeOnDelete();
            $table->foreignId('question_id')->nullable()->constrained('questions')->nullOnDelete();
            $table->unsignedInteger('position');
            $table->json('question_snapshot');
            $table->string('selected_option_key')->nullable();
            $table->boolean('is_correct')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            // Nullable `question_id` repeats as NULL once the source questions
            // are deleted, which a unique index tolerates; two live questions
            // still cannot appear twice in one attempt.
            $table->unique(['quiz_attempt_id', 'question_id']);
            $table->unique(['quiz_attempt_id', 'position']);
            $table->index(['quiz_attempt_id', 'answered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_attempt_questions');
    }
};
