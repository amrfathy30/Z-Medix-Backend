<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the student last stopped inside a subject, kept separately from study
 * sessions: ending a session does not lose the place, and resuming does not
 * depend on a session being open.
 *
 * A student stops either on a chapter page or inside a quiz, so a position
 * carries the page or the attempt and the question they were on — never both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_study_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->foreignId('chapter_id')->nullable()->constrained('chapters')->nullOnDelete();
            $table->foreignId('chapter_page_id')->nullable()->constrained('chapter_pages')->nullOnDelete();
            $table->foreignId('quiz_attempt_id')->nullable()->constrained('quiz_attempts')->nullOnDelete();
            $table->foreignId('quiz_attempt_question_id')->nullable()->constrained('quiz_attempt_questions')->nullOnDelete();
            $table->timestamps();

            // One position per subject: it is replaced as the student moves, not
            // appended to.
            $table->unique(['user_id', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_study_positions');
    }
};
