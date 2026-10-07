<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records that a student finished a chapter, which is what makes the next
 * chapter accessible.
 *
 * A row exists only for a completed chapter, so there is no status column and
 * no stored unlocked flag: access to a chapter is derived by asking whether the
 * previous published chapter has a row here.
 *
 * Completion is written when the chapter's quiz attempt completes. It is kept
 * as its own record rather than derived from quiz_attempts so that replacing a
 * chapter's quiz can never silently re-lock a chapter the student finished.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_chapter_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('chapter_id')->constrained('chapters')->cascadeOnDelete();
            $table->timestamp('completed_at');
            $table->timestamps();

            $table->unique(['user_id', 'chapter_id']);
            $table->index(['user_id', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_chapter_progress');
    }
};
