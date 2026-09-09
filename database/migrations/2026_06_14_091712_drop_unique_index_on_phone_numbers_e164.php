<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the DB-level unique constraint on e164_number so that global uniqueness
     * can be enforced at the application layer only (configurable via phone.unique_globally).
     */
    public function up(): void
    {
        Schema::table('phone_numbers', function (Blueprint $table) {
            $table->dropUnique(['e164_number']);
        });
    }

    public function down(): void
    {
        Schema::table('phone_numbers', function (Blueprint $table) {
            $table->unique('e164_number');
        });
    }
};
