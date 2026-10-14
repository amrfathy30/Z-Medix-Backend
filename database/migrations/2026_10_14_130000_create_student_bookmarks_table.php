<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A passage of a chapter page a student bookmarked to come back to.
 *
 * Shaped exactly like `student_highlights`: the selected text is stored rather
 * than a position in the rendered page, so a bookmark survives the page being
 * re-rendered and can later be read as an AI source on its own. Locating it
 * visually inside the page stays the frontend's concern.
 *
 * Only the page is referenced; the chapter and subject are reached through it,
 * so moving a page never leaves a bookmark pointing at the wrong chapter. PDF
 * books are out of scope: there is no page row to anchor a bookmark to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_bookmarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('chapter_page_id')->constrained('chapter_pages')->cascadeOnDelete();
            $table->text('selected_text');
            $table->timestamps();

            // This student's bookmarks on one page.
            $table->index(['user_id', 'chapter_page_id']);
            // The dedicated collection screen: this student's bookmarks,
            // newest first, across every page.
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_bookmarks');
    }
};
