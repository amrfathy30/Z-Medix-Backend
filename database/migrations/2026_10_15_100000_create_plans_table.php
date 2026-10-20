<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A subscription plan the admin sells. Named `plans` rather than anything
 * subscription-flavoured on purpose: the Stripe/Cashier `subscriptions` table
 * already exists and is unrelated to this manual-payment flow.
 *
 * A plan has one price per billing cycle; a null price means the cycle is not
 * offered. Plans that were ever sold are soft deleted, because periods keep
 * pointing at the plan they were paid for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->json('name');
            $table->json('description')->nullable();
            $table->decimal('monthly_price', 10, 2)->nullable();
            $table->decimal('yearly_price', 10, 2)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
