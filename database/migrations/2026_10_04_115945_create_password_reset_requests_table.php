<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Short-lived, single-use tokens handed to a student after they verify a
 * password reset OTP.
 *
 * Separate from `password_reset_tokens`, which stays in place for the Filament
 * admin panel's link-based reset: that table is keyed by email and is shared by
 * the `users` and `admins` brokers, so it cannot express per-request expiry or
 * single-use consumption.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // SHA-256 of the token; the plaintext is never stored.
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_requests');
    }
};
