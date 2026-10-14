<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A PDF a student uploaded to their own library, filed under one subject.
 *
 * Deliberately a separate table from `books`: a platform book is admin-authored
 * study content with a publication status and an order inside its subject,
 * while this is one student's private file. Keeping them apart means no query
 * over platform books can ever reach a student's upload, and no ownership
 * column has to be bolted onto content that has no owner.
 *
 * The PDF itself is held by the media library on a private disk, so the file is
 * only ever served through an authenticated, ownership-checked route — never by
 * a public URL. Only the metadata lives here.
 *
 * `title` and `author` are the student's own description of the file and are the
 * metadata a later AI/RAG source picker would list; no text extraction,
 * indexing or embedding is implied by this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_private_books', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // A private book is always filed under a subject. The subject going
            // away takes the student's filing with it rather than leaving an
            // unreachable book behind.
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->string('title');
            $table->string('author')->nullable();
            $table->timestamps();

            // The library screen: this student's books, newest first.
            $table->index(['user_id', 'created_at']);
            // The same library filtered to one subject.
            $table->index(['user_id', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_private_books');
    }
};
