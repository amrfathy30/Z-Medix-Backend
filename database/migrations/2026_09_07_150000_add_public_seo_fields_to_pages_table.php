<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->json('public_path')->nullable()->after('meta_description');
            $table->string('canonical_url')->nullable()->after('public_path');
            $table->boolean('is_indexable')->default(true)->after('canonical_url');
            $table->boolean('include_in_sitemap')->default(true)->after('is_indexable');
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn(['public_path', 'canonical_url', 'is_indexable', 'include_in_sitemap']);
        });
    }
};
