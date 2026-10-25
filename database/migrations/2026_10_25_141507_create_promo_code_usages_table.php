<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_code_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('promo_code_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_request_id')->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('reserved');
            $table->string('code', 64);
            $table->string('plan_name');
            $table->string('billing_cycle', 16);
            $table->decimal('original_price', 10, 2);
            $table->decimal('discount_percentage', 5, 2);
            $table->decimal('discount_amount', 10, 2);
            $table->decimal('final_price', 10, 2);
            $table->string('currency', 3);
            $table->timestamps();
            $table->index(['promo_code_id', 'status']);
            $table->index(['promo_code_id', 'user_id', 'status'], 'promo_usage_student_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_code_usages');
    }
};
