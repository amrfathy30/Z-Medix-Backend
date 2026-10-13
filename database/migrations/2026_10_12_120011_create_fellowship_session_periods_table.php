<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A stretch of time the student was actually working on a session.
 *
 * Study Time is the sum of these, not the span from the session's creation to
 * now: a session paused overnight must not bill the night. A period opens when
 * the session is started or resumed and closes on Pause or on completion.
 *
 * `open_marker` is how "at most one open period per session" becomes a database
 * rule instead of a hope. It holds 1 while the period is open and NULL once it
 * closes; both MySQL and SQLite treat NULLs in a unique index as distinct, so
 * the unique pair admits a single open row per session and any number of closed
 * ones.
 *
 * There is no heartbeat and no inactivity timeout — nothing closes a period but
 * an explicit Pause or completion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fellowship_session_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fellowship_quiz_session_id')
                ->constrained('fellowship_quiz_sessions')
                ->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedTinyInteger('open_marker')->nullable();
            $table->timestamps();

            $table->unique(['fellowship_quiz_session_id', 'open_marker'], 'fellowship_session_single_open_period');
            $table->index(['fellowship_quiz_session_id', 'started_at'], 'fellowship_session_period_started_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fellowship_session_periods');
    }
};
