<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A study session only tracks how long a student spent studying a subject.
 *
 * Sessions are never auto-closed and more than one may be open at a time: the
 * student is responsible for ending the one they started, so `ended_at` and
 * `duration_seconds` stay null until they do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            // Written once on end, so total study time never has to re-derive a
            // duration from timestamps across every session of a subject.
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'subject_id']);
            $table->index(['user_id', 'ended_at']);
            $table->index(['user_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('study_sessions');
    }
};
