<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A piece of text a student highlighted on a chapter page.
 *
 * The selected text is stored rather than a position in the rendered page, so a
 * highlight survives the page being re-rendered and can later be read as an AI
 * source on its own. Locating it visually inside the page stays the frontend's
 * concern.
 *
 * Only the page is referenced: the chapter and subject are reached through it,
 * so moving a page never leaves a highlight pointing at the wrong chapter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_highlights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('chapter_page_id')->constrained('chapter_pages')->cascadeOnDelete();
            $table->text('selected_text');
            $table->timestamps();

            // The only read there is: this student's highlights on one page.
            $table->index(['user_id', 'chapter_page_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_highlights');
    }
};
