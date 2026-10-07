<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per chapter page the student has completed.
 *
 * Reading progress is counted from these rows against the chapter's live page
 * count, so no percentage is stored and adding or removing a page re-weighs the
 * chapter automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_chapter_page_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('chapter_page_id')->constrained('chapter_pages')->cascadeOnDelete();
            $table->timestamp('completed_at');
            $table->timestamps();

            $table->unique(['user_id', 'chapter_page_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_chapter_page_progress');
    }
};
