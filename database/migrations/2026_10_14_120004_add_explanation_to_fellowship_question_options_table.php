<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why one answer option is right, or why it is wrong.
 *
 * Per option, and separate from the question-level `explanation`, which stays
 * exactly as it was. Nullable: an import or a form that says nothing about an
 * option's explanation leaves it empty, which is what every existing option is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fellowship_question_options', function (Blueprint $table) {
            $table->text('explanation')->nullable()->after('option_text');
        });
    }

    public function down(): void
    {
        Schema::table('fellowship_question_options', function (Blueprint $table) {
            $table->dropColumn('explanation');
        });
    }
};
