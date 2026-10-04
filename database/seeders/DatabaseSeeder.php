<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            // Country reference data is required before students can register.
            WorldSeeder::class,
            RolesPermissionsSeeder::class,
            SuperAdminSeeder::class,
            CmsSeeder::class,
            PageSectionSeeder::class,
        ]);
    }
}
