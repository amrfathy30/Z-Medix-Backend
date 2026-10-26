<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_requests', function (Blueprint $table): void {
            $table->string('request_type', 16)->default('new');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_requests', function (Blueprint $table): void {
            $table->dropColumn('request_type');
        });
    }
};
