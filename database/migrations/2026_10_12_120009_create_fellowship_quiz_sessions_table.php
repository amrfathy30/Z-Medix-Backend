<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One student-generated session over a Fellowship's bank. A student may hold
 * any number of them at once, each with its own title, question set and clock.
 *
 * `current_position` is where the student is. It is persisted rather than
 * derived so a paused session resumes on exactly the question it stopped on,
 * even after the answered ones are reviewed back and forth.
 *
 * `question_time_limit_seconds` is a snapshot of the platform limit taken at
 * generation, so the admin raising or lowering that limit later cannot change
 * how a running session is judged. It is null when the student generated the
 * session with timing off, and there is no total session limit in either case.
 *
 * `generation_criteria` keeps the filters, difficulties and question status the
 * session was generated from — history, not something the session is re-run
 * against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fellowship_quiz_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('fellowship_id')->constrained('fellowships')->cascadeOnDelete();
            $table->string('title');
            $table->string('status')->default('in_progress');
            $table->unsignedInteger('question_count');
            $table->unsignedInteger('current_position')->default(1);
            $table->boolean('timer_enabled')->default(false);
            $table->unsignedInteger('question_time_limit_seconds')->nullable();
            $table->json('generation_criteria')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'fellowship_id', 'status']);
            $table->index(['user_id', 'fellowship_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fellowship_quiz_sessions');
    }
};
