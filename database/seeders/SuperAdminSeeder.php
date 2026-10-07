<?php

namespace Database\Seeders;

use App\Enums\AccountStatus;
use App\Enums\AdminType;
use App\Models\Admin;
use Illuminate\Database\Seeder;

class SuperAdminSeeder extends Seeder
{
    /**
     * Safe to re-run on a live environment: the configured password and name are
     * only written when the account is first created. A later run never
     * overwrites credentials a real super admin has since changed — it only
     * re-asserts the account's type, active status and role.
     */
    public function run(): void
    {
        $superAdmin = Admin::firstOrCreate(
            ['email' => config('auth_features.super_admin.email', 'admin@pulvent.com')],
            [
                'name' => 'Super Admin',
                'password' => config('auth_features.super_admin.password', 'change-me'),
                'type' => AdminType::SuperAdmin,
                'status' => AccountStatus::Active,
            ]
        );

        $superAdmin->forceFill([
            'type' => AdminType::SuperAdmin,
            'status' => AccountStatus::Active,
        ])->save();

        $superAdmin->assignRole('super_admin');
    }
}
