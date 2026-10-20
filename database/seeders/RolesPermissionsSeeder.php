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

            // Subject learning content (subjects -> chapters -> pages/quizzes -> questions,
            // and subjects -> books -> pages).
            // Deliberately outside the content.* family: this is product study
            // material, not marketing website content.
            'subjects.view' => ['display_name_ar' => 'عرض المواد', 'display_name_en' => 'View Subjects'],
            'subjects.create' => ['display_name_ar' => 'إنشاء المواد', 'display_name_en' => 'Create Subjects'],
            'subjects.update' => ['display_name_ar' => 'تعديل المواد', 'display_name_en' => 'Update Subjects'],
            'subjects.delete' => ['display_name_ar' => 'حذف المواد', 'display_name_en' => 'Delete Subjects'],

            'chapters.view' => ['display_name_ar' => 'عرض الفصول', 'display_name_en' => 'View Chapters'],
            'chapters.create' => ['display_name_ar' => 'إنشاء الفصول', 'display_name_en' => 'Create Chapters'],
            'chapters.update' => ['display_name_ar' => 'تعديل الفصول', 'display_name_en' => 'Update Chapters'],
            'chapters.delete' => ['display_name_ar' => 'حذف الفصول', 'display_name_en' => 'Delete Chapters'],

            'chapter_pages.view' => ['display_name_ar' => 'عرض صفحات الفصول', 'display_name_en' => 'View Chapter Pages'],
            'chapter_pages.create' => ['display_name_ar' => 'إنشاء صفحات الفصول', 'display_name_en' => 'Create Chapter Pages'],
            'chapter_pages.update' => ['display_name_ar' => 'تعديل صفحات الفصول', 'display_name_en' => 'Update Chapter Pages'],
            'chapter_pages.delete' => ['display_name_ar' => 'حذف صفحات الفصول', 'display_name_en' => 'Delete Chapter Pages'],

            'books.view' => ['display_name_ar' => 'عرض الكتب', 'display_name_en' => 'View Books'],
            'books.create' => ['display_name_ar' => 'إنشاء الكتب', 'display_name_en' => 'Create Books'],
            'books.update' => ['display_name_ar' => 'تعديل الكتب', 'display_name_en' => 'Update Books'],
            'books.delete' => ['display_name_ar' => 'حذف الكتب', 'display_name_en' => 'Delete Books'],

            'book_pages.view' => ['display_name_ar' => 'عرض صفحات الكتب', 'display_name_en' => 'View Book Pages'],
            'book_pages.create' => ['display_name_ar' => 'إنشاء صفحات الكتب', 'display_name_en' => 'Create Book Pages'],
            'book_pages.update' => ['display_name_ar' => 'تعديل صفحات الكتب', 'display_name_en' => 'Update Book Pages'],
            'book_pages.delete' => ['display_name_ar' => 'حذف صفحات الكتب', 'display_name_en' => 'Delete Book Pages'],

            'quizzes.view' => ['display_name_ar' => 'عرض الاختبارات', 'display_name_en' => 'View Quizzes'],
            'quizzes.create' => ['display_name_ar' => 'إنشاء الاختبارات', 'display_name_en' => 'Create Quizzes'],
            'quizzes.update' => ['display_name_ar' => 'تعديل الاختبارات', 'display_name_en' => 'Update Quizzes'],
            'quizzes.delete' => ['display_name_ar' => 'حذف الاختبارات', 'display_name_en' => 'Delete Quizzes'],

            'questions.view' => ['display_name_ar' => 'عرض الأسئلة', 'display_name_en' => 'View Questions'],
            'questions.create' => ['display_name_ar' => 'إنشاء الأسئلة', 'display_name_en' => 'Create Questions'],
            'questions.update' => ['display_name_ar' => 'تعديل الأسئلة', 'display_name_en' => 'Update Questions'],
            'questions.delete' => ['display_name_ar' => 'حذف الأسئلة', 'display_name_en' => 'Delete Questions'],

            // Fellowship question banks (fellowships -> questions/filters, fed by
            // Excel imports). Its own permission family, separate from the
            // Subject tree: a Fellowship has no chapters and no reading pages,
            // and a role may be trusted with one bank and not the other.
            'fellowships.view' => ['display_name_ar' => 'عرض الزمالات', 'display_name_en' => 'View Fellowships'],
            'fellowships.create' => ['display_name_ar' => 'إنشاء الزمالات', 'display_name_en' => 'Create Fellowships'],
            'fellowships.update' => ['display_name_ar' => 'تعديل الزمالات', 'display_name_en' => 'Update Fellowships'],
            'fellowships.delete' => ['display_name_ar' => 'حذف الزمالات', 'display_name_en' => 'Delete Fellowships'],

            'fellowship_questions.view' => ['display_name_ar' => 'عرض أسئلة الزمالات', 'display_name_en' => 'View Fellowship Questions'],
            'fellowship_questions.create' => ['display_name_ar' => 'إنشاء أسئلة الزمالات', 'display_name_en' => 'Create Fellowship Questions'],
            'fellowship_questions.update' => ['display_name_ar' => 'تعديل أسئلة الزمالات', 'display_name_en' => 'Update Fellowship Questions'],
            'fellowship_questions.delete' => ['display_name_ar' => 'حذف أسئلة الزمالات', 'display_name_en' => 'Delete Fellowship Questions'],

            'fellowship_filters.view' => ['display_name_ar' => 'عرض فلاتر الزمالات', 'display_name_en' => 'View Fellowship Filters'],
            'fellowship_filters.create' => ['display_name_ar' => 'إنشاء فلاتر الزمالات', 'display_name_en' => 'Create Fellowship Filters'],
            'fellowship_filters.update' => ['display_name_ar' => 'تعديل فلاتر الزمالات', 'display_name_en' => 'Update Fellowship Filters'],
            'fellowship_filters.delete' => ['display_name_ar' => 'حذف فلاتر الزمالات', 'display_name_en' => 'Delete Fellowship Filters'],

            // Report cases — admin-authored case write-ups with their source PDF,
            // read by students. Its own permission family, like the rest of the
            // learning content.
            'report_cases.view' => ['display_name_ar' => 'عرض الحالات', 'display_name_en' => 'View Report Cases'],
            'report_cases.create' => ['display_name_ar' => 'إنشاء الحالات', 'display_name_en' => 'Create Report Cases'],
            'report_cases.update' => ['display_name_ar' => 'تعديل الحالات', 'display_name_en' => 'Update Report Cases'],
            'report_cases.delete' => ['display_name_ar' => 'حذف الحالات', 'display_name_en' => 'Delete Report Cases'],

            // Plans and subscriptions. `plans.*` is pricing and features;
            // `subscriptions.*` covers the whole manual-payment workflow
            // (requests, subscriptions and the periods/invoices recorded on
            // them), since the person taking payment on WhatsApp does all of it.
            'plans.view' => ['display_name_ar' => 'عرض الباقات', 'display_name_en' => 'View Plans'],
            'plans.create' => ['display_name_ar' => 'إنشاء الباقات', 'display_name_en' => 'Create Plans'],
            'plans.update' => ['display_name_ar' => 'تعديل الباقات', 'display_name_en' => 'Update Plans'],
            'plans.delete' => ['display_name_ar' => 'حذف الباقات', 'display_name_en' => 'Delete Plans'],

            'subscriptions.view' => ['display_name_ar' => 'عرض الاشتراكات', 'display_name_en' => 'View Subscriptions'],
            'subscriptions.create' => ['display_name_ar' => 'إنشاء الاشتراكات', 'display_name_en' => 'Create Subscriptions'],
            'subscriptions.update' => ['display_name_ar' => 'تعديل الاشتراكات', 'display_name_en' => 'Update Subscriptions'],
            'subscriptions.delete' => ['display_name_ar' => 'حذف الاشتراكات', 'display_name_en' => 'Delete Subscriptions'],

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
            'subjects.view',
            'chapters.view',
            'chapter_pages.view',
            'books.view',
            'book_pages.view',
            'quizzes.view',
            'questions.view',
            'fellowships.view',
            'fellowship_questions.view',
            'fellowship_filters.view',
            // Report cases are the one learning entity the admin role manages
            // outright, not just reads.
            'report_cases.view',
            'report_cases.create',
            'report_cases.update',
            'report_cases.delete',
            // The admin role takes the payments: it handles requests and
            // records periods, but only reads the plans' pricing.
            'plans.view',
            'subscriptions.view',
            'subscriptions.create',
            'subscriptions.update',
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
            'subjects.view',
            'subjects.create',
            'subjects.update',
            'subjects.delete',
            'chapters.view',
            'chapters.create',
            'chapters.update',
            'chapters.delete',
            'chapter_pages.view',
            'chapter_pages.create',
            'chapter_pages.update',
            'chapter_pages.delete',
            'books.view',
            'books.create',
            'books.update',
            'books.delete',
            'book_pages.view',
            'book_pages.create',
            'book_pages.update',
            'book_pages.delete',
            'quizzes.view',
            'quizzes.create',
            'quizzes.update',
            'quizzes.delete',
            'questions.view',
            'questions.create',
            'questions.update',
            'questions.delete',
            'fellowships.view',
            'fellowships.create',
            'fellowships.update',
            'fellowships.delete',
            'fellowship_questions.view',
            'fellowship_questions.create',
            'fellowship_questions.update',
            'fellowship_questions.delete',
            'fellowship_filters.view',
            'fellowship_filters.create',
            'fellowship_filters.update',
            'fellowship_filters.delete',
            'report_cases.view',
            'report_cases.create',
            'report_cases.update',
            'report_cases.delete',
        ]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
