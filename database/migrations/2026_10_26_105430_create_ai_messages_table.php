<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->foreignId('reply_to_message_id')->nullable()->unique()->constrained('ai_messages')->cascadeOnDelete();
            $table->string('role', 16);
            $table->text('content')->nullable();
            $table->string('status', 16)->default('pending');
            $table->string('client_message_id', 100)->nullable();
            $table->uuid('correlation_id');
            $table->uuid('processing_token')->nullable();
            $table->string('error_code', 40)->nullable();
            $table->unsignedSmallInteger('processing_attempts')->default(0);
            $table->timestamps();
            $table->unique(['conversation_id', 'client_message_id']);
            $table->index(['conversation_id', 'id']);
            $table->index(['role', 'status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
    }
};
