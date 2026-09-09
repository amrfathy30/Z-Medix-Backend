<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            // Admin management
            'admins.view' => ['display_name_ar' => 'عرض الموظفين', 'display_name_en' => 'View Employees'],
            'admins.create' => ['display_name_ar' => 'إضافة موظفين', 'display_name_en' => 'Create Employees'],
            'admins.update' => ['display_name_ar' => 'تعديل الموظفين', 'display_name_en' => 'Update Employees'],
            'admins.delete' => ['display_name_ar' => 'حذف الموظفين', 'display_name_en' => 'Delete Employees'],

            // Role management
            'roles.view' => ['display_name_ar' => 'عرض الأدوار', 'display_name_en' => 'View Roles'],
            'roles.create' => ['display_name_ar' => 'إنشاء الأدوار', 'display_name_en' => 'Create Roles'],
            'roles.update' => ['display_name_ar' => 'تعديل الأدوار', 'display_name_en' => 'Update Roles'],
            'roles.delete' => ['display_name_ar' => 'حذف الأدوار', 'display_name_en' => 'Delete Roles'],

            // CMS content management
            'content.view' => ['display_name_ar' => 'عرض المحتوى', 'display_name_en' => 'View Content'],
            'content.create' => ['display_name_ar' => 'إنشاء المحتوى', 'display_name_en' => 'Create Content'],
            'content.update' => ['display_name_ar' => 'تعديل المحتوى', 'display_name_en' => 'Update Content'],
            'content.delete' => ['display_name_ar' => 'حذف المحتوى', 'display_name_en' => 'Delete Content'],
            'content.publish' => ['display_name_ar' => 'نشر المحتوى', 'display_name_en' => 'Publish Content'],

            // Settings management
            'settings.view' => ['display_name_ar' => 'عرض الإعدادات', 'display_name_en' => 'View Settings'],
            'settings.update' => ['display_name_ar' => 'تعديل الإعدادات', 'display_name_en' => 'Update Settings'],

            // Integrations settings (e.g. Google Analytics) — kept separate
            // from settings.* since it's a different audience: engineers
            // wiring up an integration, not general site editors.
            'settings.integrations.view' => ['display_name_ar' => 'عرض إعدادات التكاملات', 'display_name_en' => 'View Integrations Settings'],
            'settings.integrations.update' => ['display_name_ar' => 'تعديل إعدادات التكاملات', 'display_name_en' => 'Update Integrations Settings'],

            // Marketing tracking pixels. Deliberately OUTSIDE the settings.*
            // family: a pixel ID is tracking/analytics configuration with
            // privacy implications, not website content — only the super
            // admin holds it by default.
            'marketing.pixels.view' => ['display_name_ar' => 'عرض إعدادات البيكسل', 'display_name_en' => 'View Pixel Settings'],
            'marketing.pixels.update' => ['display_name_ar' => 'تعديل إعدادات البيكسل', 'display_name_en' => 'Update Pixel Settings'],

            // Reports — read-only traffic/analytics reporting, broader than
            // editing integration credentials.
            'reports.view' => ['display_name_ar' => 'عرض التقارير', 'display_name_en' => 'View Reports'],

            // Contact messages
            'contact_messages.view' => ['display_name_ar' => 'عرض الرسائل', 'display_name_en' => 'View Contact Messages'],
            'contact_messages.update' => ['display_name_ar' => 'تعديل الرسائل', 'display_name_en' => 'Update Contact Messages'],
            'contact_messages.delete' => ['display_name_ar' => 'حذف الرسائل', 'display_name_en' => 'Delete Contact Messages'],
        ];

        foreach ($permissions as $name => $displayNames) {
            $permission = Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'admin'],
                $displayNames
            );
            $permission->update($displayNames);
        }

        $superAdmin = Role::firstOrCreate(
            ['name' => 'super_admin', 'guard_name' => 'admin'],
            ['display_name_ar' => 'مدير عام', 'display_name_en' => 'Super Admin']
        );
        $superAdmin->update(['display_name_ar' => 'مدير عام', 'display_name_en' => 'Super Admin']);

        $admin = Role::firstOrCreate(
            ['name' => 'admin', 'guard_name' => 'admin'],
            ['display_name_ar' => 'مدير', 'display_name_en' => 'Admin']
        );
        $admin->update(['display_name_ar' => 'مدير', 'display_name_en' => 'Admin']);

        $contentManager = Role::firstOrCreate(
            ['name' => 'content_manager', 'guard_name' => 'admin'],
            ['display_name_ar' => 'مشرف محتوى', 'display_name_en' => 'Content Manager']
        );
        $contentManager->update(['display_name_ar' => 'مشرف محتوى', 'display_name_en' => 'Content Manager']);

        $superAdmin->syncPermissions(
            Permission::where('guard_name', 'admin')->get()
        );

        $admin->syncPermissions([
            'admins.view',
            'admins.create',
            'admins.update',
            'roles.view',
            'content.view',
            'reports.view',
        ]);

        $contentManager->syncPermissions([
            'admins.view',
            'roles.view',
            'content.view',
            'content.create',
            'content.update',
            'content.delete',
            'content.publish',
            'settings.view',
            'settings.update',
            'contact_messages.view',
            'contact_messages.update',
        ]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
