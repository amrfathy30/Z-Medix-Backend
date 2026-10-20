<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A student asking for a plan. Payment happens outside the app (WhatsApp), so
 * this is only an inbox item for the admin: approving it records a period.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->string('billing_cycle', 16);
            $table->string('status', 16)->default('pending');
            $table->text('admin_note')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();

            // "Does this student already have a pending request" and the admin
            // inbox filtered by status.
            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_requests');
    }
};
