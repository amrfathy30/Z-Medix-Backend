<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * These columns were added to the users table in a previously-run migration
 * whose file was later deleted. This migration restores the documented schema
 * for fresh-install compatibility. It is safe to run against an existing
 * database that already has these columns — each column and index is only
 * added if it does not already exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'status')) {
                $table->string('status')->default('pending')->after('password');
            }

            if (! Schema::hasColumn('users', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable()->after('status');
            }

            if (! Schema::hasColumn('users', 'deleted_at')) {
                $table->softDeletes()->after('last_login_at');
            }

            if (! Schema::hasIndex('users', 'users_status_index')) {
                $table->index('status');
            }
        });
    }

    /*
     * down() is intentionally a no-op.
     *
     * These columns pre-existed in the database before this migration file
     * was documented, so rolling back automatically would risk dropping live
     * data on any environment where the columns were added historically.
     *
     * To roll back manually on a clean environment, drop:
     *   users.status, users.last_login_at, users.deleted_at
     * and remove the corresponding index users_status_index.
     */
    public function down(): void
    {
        // Intentionally empty — see docblock above.
    }
};
