<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Free-text profile fields shown on the student profile screen.
 *
 * Deliberately plain nullable strings: there is no university/faculty hierarchy
 * in scope, so neither value is a reference to a lookup table.
 *
 * No columns are added for the profile photo or for verification state. The photo
 * lives in the media library, email verification is derived from
 * users.email_verified_at, and phone verification from phone_numbers.status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('institution')->nullable()->after('country_id');
            $table->string('field_of_study')->nullable()->after('institution');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['institution', 'field_of_study']);
        });
    }
};
