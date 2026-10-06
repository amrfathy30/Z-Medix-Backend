<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quizzes', function (Blueprint $table) {
            $table->id();
            // A chapter has exactly one quiz, enforced at the database level.
            $table->foreignId('chapter_id')->unique()->constrained('chapters')->cascadeOnDelete();
            $table->string('title');
            $table->string('difficulty');
            $table->timestamps();
            $table->softDeletes();

            $table->index('difficulty');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quizzes');
    }
};
