<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Fellowship: the exam-prep question bank a student picks from the Question
 * Bank screen.
 *
 * Deliberately its own tree, parallel to `subjects` rather than inside it — a
 * Fellowship has no chapters, no reading pages and no books, and its questions
 * and sessions never mix with chapter quizzes.
 *
 * The Fellowship must exist before any question can be uploaded into it, which
 * is why every import is addressed through one of these records.
 *
 * `performance_filter_id` is added by a later migration, once
 * `fellowship_filters` exists for it to point at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fellowships', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fellowships');
    }
};
