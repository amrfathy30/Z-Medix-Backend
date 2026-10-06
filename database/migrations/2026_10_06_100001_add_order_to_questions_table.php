<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Questions are presented in an explicit, admin-controlled order within their
 * quiz, set by drag-and-drop rather than typed in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->unsignedInteger('order')->default(0)->after('question');
            $table->index(['quiz_id', 'order']);
        });

        // Give existing rows a deterministic sequence within their quiz instead
        // of leaving every question at 0.
        $position = [];

        DB::table('questions')->orderBy('quiz_id')->orderBy('id')->each(function (object $question) use (&$position): void {
            $position[$question->quiz_id] = ($position[$question->quiz_id] ?? 0) + 1;

            DB::table('questions')
                ->where('id', $question->id)
                ->update(['order' => $position[$question->quiz_id]]);
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropIndex(['quiz_id', 'order']);
            $table->dropColumn('order');
        });
    }
};
