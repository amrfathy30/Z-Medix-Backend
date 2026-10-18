<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Free text the admin types, e.g. "Fundamentals" or "Clinical Medicine".
     * Deliberately not an entity: it groups books on the student's Books tab
     * and nothing in the application reads it as a value.
     */
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->string('classification')->nullable()->after('author');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn('classification');
        });
    }
};
