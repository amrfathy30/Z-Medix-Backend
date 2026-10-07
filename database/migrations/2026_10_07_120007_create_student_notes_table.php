<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A note a student wrote against a piece of text on a chapter page.
 *
 * `selected_text` is the passage the note was written about and stays with the
 * note for its whole life; `content` is the student's own writing and is the
 * only part an update may change. Both are stored so the pair can later be read
 * as an AI source without the page having to be re-parsed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('chapter_page_id')->constrained('chapter_pages')->cascadeOnDelete();
            $table->text('selected_text');
            $table->text('content');
            $table->timestamps();

            // The only read there is: this student's notes on one page.
            $table->index(['user_id', 'chapter_page_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_notes');
    }
};
