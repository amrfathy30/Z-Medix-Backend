<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One run of the question import wizard, kept as history after it finishes.
 *
 * The import always belongs to a Fellowship: it is opened from that record, so
 * nobody ever types a Fellowship id into the wizard.
 *
 * `header_map` is the admin's decision for every uploaded header — which system
 * field, which existing filter, which new filter, or ignored. It is stored
 * rather than applied immediately, because confirmation needs to know which
 * optional filter columns were part of *this* file: a filter the file never
 * mentioned must keep its existing values on an update, while one it mentioned
 * with an empty cell must clear them.
 *
 * `status` is also the concurrency guard. Confirmation moves `staged` to
 * `processing` under a row lock and only ever runs against a `staged` import,
 * so a double-clicked confirm cannot insert the same questions twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fellowship_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fellowship_id')->constrained('fellowships')->cascadeOnDelete();
            // Who ran it. Kept as history after the admin account is removed.
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('status')->default('draft');
            $table->string('original_filename');
            $table->string('stored_path')->nullable();
            $table->json('headers')->nullable();
            $table->json('header_map')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->unsignedInteger('created_questions')->default(0);
            $table->unsignedInteger('updated_questions')->default(0);
            $table->unsignedInteger('skipped_questions')->default(0);
            $table->text('failure_reason')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['fellowship_id', 'status']);
            $table->index(['fellowship_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fellowship_imports');
    }
};
