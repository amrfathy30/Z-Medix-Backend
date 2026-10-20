<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A student's subscription: the container that their paid periods hang off.
 *
 * One row per student (renewals add periods, they do not add subscriptions).
 * There is deliberately no status column: whether the subscription is active is
 * decided by its periods and `cancelled_at`, so access ends exactly when the
 * paid period does without anything having to run on a schedule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_subscriptions');
    }
};
