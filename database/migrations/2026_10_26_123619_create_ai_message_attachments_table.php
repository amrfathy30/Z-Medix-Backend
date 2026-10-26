<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_message_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_message_id')->constrained('ai_messages')->cascadeOnDelete();
            $table->string('reference_type', 20);
            $table->unsignedBigInteger('reference_id');
            $table->json('snapshot');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['ai_message_id', 'reference_type', 'reference_id'], 'ai_attachments_message_reference_unique');
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_message_attachments');
    }
};
