<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('phone_verification_otps', function (Blueprint $table) {
            $table->string('target_phone', 32)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('phone_verification_otps', function (Blueprint $table) {
            $table->dropColumn('target_phone');
        });
    }
};
