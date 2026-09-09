<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faq_category_id')->nullable()->constrained('faq_categories')->nullOnDelete();
            $table->json('question');
            $table->json('answer');
            $table->string('status')->default('published');
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('sort_order');
            $table->index('created_by_admin_id');
            $table->index('updated_by_admin_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faqs');
    }
};
