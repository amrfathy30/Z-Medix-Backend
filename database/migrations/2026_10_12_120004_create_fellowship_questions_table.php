<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A question in a Fellowship's bank. Entirely separate from `questions`, which
 * belongs to chapter quizzes and keeps its own rules.
 *
 * `performance_filter_value_id` is the question's single Performance Topic. It
 * lives here, as a column, and deliberately NOT also as a row in
 * `fellowship_question_filter_values`: the topic is required of every question
 * and is read on nearly every analytics query, so storing it twice would only
 * create two places for it to disagree. The optional dynamic filters, which a
 * question may or may not have, live in the pivot.
 *
 * `content_hash` is a hash of the normalized question text, written on save. It
 * is what duplicate detection compares against, so finding the candidates for
 * an uploaded row is an index lookup rather than a scan of the whole bank.
 *
 * Deletes are soft. A session's questions are served from their own snapshots,
 * so deleting or rewording a question here cannot alter a session a student has
 * already generated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fellowship_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fellowship_id')->constrained('fellowships')->cascadeOnDelete();
            $table->text('question_text');
            $table->text('explanation')->nullable();
            $table->string('difficulty');
            // Every question carries exactly one Performance Topic. The column
            // is nullable only so a half-built question can be reported against
            // its own row rather than failing the whole write; the writer and
            // the admin form both require one.
            //
            // Restricted rather than nulled on delete: a topic value still in
            // use must not be deletable, because nulling it here would silently
            // unclassify live questions. Re-file them first, which is also what
            // the admin UI tells the admin to do. Past sessions are unaffected
            // either way — they carry their own captured copy.
            $table->foreignId('performance_filter_value_id')
                ->nullable()
                ->constrained('fellowship_filter_values')
                ->restrictOnDelete();
            $table->string('content_hash', 64);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['fellowship_id', 'content_hash']);
            $table->index(['fellowship_id', 'difficulty']);
            // Named explicitly: the derived name would exceed the 64-character
            // limit MySQL puts on an identifier.
            $table->index(['fellowship_id', 'performance_filter_value_id'], 'fellowship_questions_topic_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fellowship_questions');
    }
};
