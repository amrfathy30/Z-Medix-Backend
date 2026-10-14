<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A short heading for a chapter-quiz question, alongside — never instead of —
 * its `question` text.
 *
 * Nullable, so every question written before this column stays valid and
 * answerable with no title at all. Attempts already generated are unaffected:
 * they are served from their own snapshots.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->string('title', 255)->nullable()->after('quiz_id');
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn('title');
        });
    }
};
