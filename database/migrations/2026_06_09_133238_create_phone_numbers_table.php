<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_numbers', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type');
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('country_id')->nullable();
            $table->string('country_iso2', 2)->nullable();
            $table->string('country_code', 10)->nullable();
            $table->string('national_number');
            $table->string('e164_number')->unique();
            $table->boolean('is_primary')->default(false);
            $table->string('status')->default('pending');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_type', 'owner_id']);
            $table->index('is_primary');
            $table->index('status');
            $table->index('country_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_numbers');
    }
};
