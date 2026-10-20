<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One paid period of a subscription: the invoice the admin records after taking
 * payment on WhatsApp. It snapshots the plan, cycle and amount that were
 * actually agreed, so later price or plan edits never rewrite history.
 *
 * The payment screenshot is held by the media library on a private disk; only
 * the metadata lives here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_subscription_id')->constrained('student_subscriptions')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->foreignId('subscription_request_id')->nullable()->constrained('subscription_requests')->nullOnDelete();
            $table->string('billing_cycle', 16);
            // dateTime, not timestamp: MySQL's TIMESTAMP stops at 2038, which a
            // yearly period bought in 2037 would run past.
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3);
            $table->timestamp('paid_at');
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            // "Which period covers now" for one subscription.
            $table->index(['student_subscription_id', 'starts_at', 'ends_at'], 'subscription_periods_coverage_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_periods');
    }
};
