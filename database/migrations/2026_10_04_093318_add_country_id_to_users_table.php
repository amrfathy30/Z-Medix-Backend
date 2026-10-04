<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Stores the student's country as a reference to the world package `countries`
 * table. No foreign key constraint is declared, mirroring `phone_numbers.country_id`,
 * because the world package allows its tables to live on a separate connection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('country_id')->nullable()->after('email');

            $table->index('country_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['country_id']);
            $table->dropColumn('country_id');
        });
    }
};
