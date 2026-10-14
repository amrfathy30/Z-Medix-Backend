<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The answer options of one Fellowship question: at least two, exactly one of
 * them correct. Both rules are enforced by the importer and the admin form,
 * which is where a violation can be reported against the offending row.
 *
 * `order` is the order the option was read from the spreadsheet, which is the
 * order the student is shown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fellowship_question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fellowship_question_id')->constrained('fellowship_questions')->cascadeOnDelete();
            $table->text('option_text');
            $table->boolean('is_correct')->default(false);
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();

            $table->index(['fellowship_question_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fellowship_question_options');
    }
};
