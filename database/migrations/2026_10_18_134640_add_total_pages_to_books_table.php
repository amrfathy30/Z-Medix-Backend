<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The page count of a PDF book, typed by the admin.
     *
     * A content book is already counted from its page rows, so this column is
     * only ever read for a PDF one — nothing here parses the file. Nullable
     * because the figure is optional: a book whose count nobody has entered
     * reports none rather than zero.
     */
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->unsignedInteger('total_pages')->nullable()->after('classification');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn('total_pages');
        });
    }
};
