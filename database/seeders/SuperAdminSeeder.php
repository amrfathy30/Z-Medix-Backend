<?php

namespace Database\Seeders;

use App\Enums\AccountStatus;
use App\Enums\AdminType;
use App\Models\Admin;
use Illuminate\Database\Seeder;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $superAdmin = Admin::updateOrCreate(
            ['email' => config('auth_features.super_admin.email', 'admin@pulvent.com')],
            [
                'name' => 'Super Admin',
                'password' => config('auth_features.super_admin.password', 'change-me'),
                'type' => AdminType::SuperAdmin,
                'status' => AccountStatus::Active,
            ]
        );

        $superAdmin->assignRole('super_admin');
    }
}
