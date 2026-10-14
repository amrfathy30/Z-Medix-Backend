<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why one answer option is right, or why it is wrong.
 *
 * Per option, and separate from the question-level explanation, which stays
 * exactly as it was. Nullable throughout: an option with no explanation of its
 * own is the normal case for everything authored before now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('question_options', function (Blueprint $table) {
            $table->text('explanation')->nullable()->after('option_text');
        });
    }

    public function down(): void
    {
        Schema::table('question_options', function (Blueprint $table) {
            $table->dropColumn('explanation');
        });
    }
};
