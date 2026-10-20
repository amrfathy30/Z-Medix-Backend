<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The features a plan includes, each with an optional limit.
 *
 * A row means "this plan includes the feature". `limit` and `is_unlimited` only
 * mean something for a limitable feature (AI credits): unlimited, or a number
 * of credits per paid period. For the plain on/off features both stay at their
 * defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_feature_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('feature', 32);
            $table->boolean('is_unlimited')->default(false);
            $table->unsignedInteger('limit')->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'feature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_feature_limits');
    }
};
