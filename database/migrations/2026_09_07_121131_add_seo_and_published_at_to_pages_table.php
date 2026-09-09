<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->json('meta_title')->nullable()->after('label');
            $table->json('meta_description')->nullable()->after('meta_title');
            $table->timestamp('published_at')->nullable()->after('status');
            $table->index('published_at');
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropIndex(['published_at']);
            $table->dropColumn(['meta_title', 'meta_description', 'published_at']);
        });
    }
};
