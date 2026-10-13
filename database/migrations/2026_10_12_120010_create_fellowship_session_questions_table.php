<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One question inside one session: its fixed place in the generated order, the
 * frozen copy of the question it presents, the student's final answer, and that
 * question's own clock.
 *
 * `question_snapshot` is what the session is rendered and graded from — text,
 * options and their keys, the correct answer, the explanation, the difficulty,
 * the Performance Topic and the dynamic filter values. It is written once, at
 * generation, and never again, so editing or deleting the source question
 * cannot move a student's score or rewrite their history.
 *
 * `difficulty`, `performance_filter_value_id` and `topic_value` repeat three of
 * the snapshot's fields as columns. That is on purpose: Topic Performance,
 * Topics Completed and the per-session topic summaries group by them on every
 * request, and they should not have to open a JSON document per row to do it.
 * The snapshot stays the authority for display; these are its queryable index.
 *
 * None of the three is a foreign key, and the topic pair in particular is not.
 * The identity and the name together are what a past answer was counted under,
 * so deleting the live topic value must not null the id, and recreating a value
 * under the same display name must not fold the two histories together. Both
 * follow from the column being a captured value rather than a live reference.
 *
 * `answered_within_time` is advisory and nothing else. It is null when the
 * session was generated with timing off, and it never takes part in `is_correct`
 * or in any score.
 *
 * `active_seconds` and `activated_at` are this question's own timer: accumulated
 * active time, plus the instant the current stretch of it began. Pause banks the
 * stretch and clears the instant; resume starts a new one. Walking away without
 * pausing therefore keeps costing time, which is the intended behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fellowship_session_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fellowship_quiz_session_id')
                ->constrained('fellowship_quiz_sessions')
                ->cascadeOnDelete();
            // Traceability back to the question captured. Nulled when that
            // question is force-deleted; the snapshot already holds everything
            // the session needs, so nothing is restricted on its account.
            $table->foreignId('fellowship_question_id')
                ->nullable()
                ->constrained('fellowship_questions')
                ->nullOnDelete();
            $table->unsignedInteger('position');
            $table->json('question_snapshot');
            $table->string('difficulty');
            // The Performance Topic as captured, and deliberately NOT a foreign
            // key. Historical analytics group on this identity, so it must not
            // be nulled when the live filter value is deleted, nor re-point at
            // a value later recreated under the same name — either would
            // silently rewrite what a past session was counted under. It is a
            // historical reference that happens to match a live id while that
            // row still exists.
            $table->unsignedBigInteger('performance_filter_value_id')->nullable();
            // The display text as it stood at capture, so a renamed topic still
            // reads correctly in history.
            $table->string('topic_value')->nullable();
            $table->string('selected_option_key')->nullable();
            $table->boolean('is_correct')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->boolean('answered_within_time')->nullable();
            $table->unsignedInteger('active_seconds')->default(0);
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->unique(['fellowship_quiz_session_id', 'position'], 'fellowship_session_question_position_unique');
            $table->index(['fellowship_quiz_session_id', 'answered_at'], 'fellowship_session_question_answered_index');
            $table->index(['performance_filter_value_id', 'answered_at'], 'fellowship_session_question_topic_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fellowship_session_questions');
    }
};
