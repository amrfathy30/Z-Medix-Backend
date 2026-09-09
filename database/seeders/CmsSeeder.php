<?php

namespace Database\Seeders;

use App\Enums\ContentStatus;
use App\Enums\SettingValueType;
use App\Models\BlogCategory;
use App\Models\Faq;
use App\Models\FaqCategory;
use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Since Phase 3G, CmsSeeder no longer owns any Website Content `pages` rows
 * (about-us, services, privacy-policy, terms-and-conditions) — those are
 * fully owned by PageSectionSeeder + WebsiteContentDefinitions, which create
 * both the Page row and its section(s) from a single source of truth. This
 * seeder is left with the data Website Content doesn't model: blog
 * categories, FAQ categories/FAQs, and settings.
 */
class CmsSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedBlogCategories();
        $this->seedFaqCategoriesAndFaqs();
        $this->seedSettings();
    }

    private function seedBlogCategories(): void
    {
        $categories = [
            ['slug' => 'announcements', 'name' => ['en' => 'Announcements', 'ar' => 'إعلانات'], 'sort_order' => 1],
            ['slug' => 'tutorials', 'name' => ['en' => 'Tutorials', 'ar' => 'دروس'], 'sort_order' => 2],
            ['slug' => 'updates', 'name' => ['en' => 'Updates', 'ar' => 'تحديثات'], 'sort_order' => 3],
        ];

        foreach ($categories as $data) {
            BlogCategory::updateOrCreate(
                ['slug' => $data['slug']],
                array_merge($data, ['status' => ContentStatus::Published])
            );
        }
    }

    private function seedFaqCategoriesAndFaqs(): void
    {
        $faqData = [
            [
                'category' => ['slug' => 'general', 'name' => ['en' => 'General', 'ar' => 'عام'], 'sort_order' => 1],
                'faqs' => [
                    [
                        'question' => ['en' => 'What is this platform?', 'ar' => 'ما هي هذه المنصة؟'],
                        'answer' => [
                            'en' => 'Default placeholder answer. Replace it with a description of your product.',
                            'ar' => 'إجابة افتراضية مؤقتة. استبدلها بوصف منتجك.',
                        ],
                        'sort_order' => 1,
                    ],
                    [
                        'question' => ['en' => 'Is the platform available in multiple languages?', 'ar' => 'هل المنصة متاحة بلغات متعددة؟'],
                        'answer' => [
                            'en' => 'Yes, the platform is fully available in both Arabic and English.',
                            'ar' => 'نعم، المنصة متاحة بالكامل باللغتين العربية والإنجليزية.',
                        ],
                        'sort_order' => 2,
                    ],
                ],
            ],
            [
                'category' => ['slug' => 'getting-started', 'name' => ['en' => 'Getting Started', 'ar' => 'البدء'], 'sort_order' => 2],
                'faqs' => [
                    [
                        'question' => ['en' => 'How do I create an account?', 'ar' => 'كيف أنشئ حسابًا؟'],
                        'answer' => [
                            'en' => 'Default placeholder answer describing how to sign up and get started.',
                            'ar' => 'إجابة افتراضية مؤقتة توضح كيفية التسجيل والبدء.',
                        ],
                        'sort_order' => 1,
                    ],
                    [
                        'question' => ['en' => 'Can I change my account details later?', 'ar' => 'هل يمكنني تغيير بيانات حسابي لاحقًا؟'],
                        'answer' => [
                            'en' => 'Yes, you can update your account details at any time from your profile settings.',
                            'ar' => 'نعم، يمكنك تحديث بيانات حسابك في أي وقت من إعدادات الملف الشخصي.',
                        ],
                        'sort_order' => 2,
                    ],
                ],
            ],
            [
                'category' => ['slug' => 'billing', 'name' => ['en' => 'Billing', 'ar' => 'الفوترة'], 'sort_order' => 3],
                'faqs' => [
                    [
                        'question' => ['en' => 'How does billing work?', 'ar' => 'كيف تتم الفوترة؟'],
                        'answer' => [
                            'en' => 'Default placeholder answer describing the platform billing model.',
                            'ar' => 'إجابة افتراضية مؤقتة توضح نموذج الفوترة في المنصة.',
                        ],
                        'sort_order' => 1,
                    ],
                    [
                        'question' => ['en' => 'Can I cancel at any time?', 'ar' => 'هل يمكنني الإلغاء في أي وقت؟'],
                        'answer' => [
                            'en' => 'Default placeholder answer describing the cancellation policy.',
                            'ar' => 'إجابة افتراضية مؤقتة توضح سياسة الإلغاء.',
                        ],
                        'sort_order' => 2,
                    ],
                ],
            ],
        ];

        foreach ($faqData as $item) {
            $category = FaqCategory::updateOrCreate(
                ['slug' => $item['category']['slug']],
                array_merge($item['category'], ['status' => ContentStatus::Published])
            );

            foreach ($item['faqs'] as $faqItem) {
                Faq::updateOrCreate(
                    [
                        'faq_category_id' => $category->id,
                        'sort_order' => $faqItem['sort_order'],
                    ],
                    array_merge($faqItem, [
                        'faq_category_id' => $category->id,
                        'status' => ContentStatus::Published,
                    ])
                );
            }
        }
    }

    private function seedSettings(): void
    {
        $settings = [
            // ── General ──────────────────────────────────────────────────────
            ['key' => 'site_name', 'value' => 'Starter Platform', 'value_type' => SettingValueType::String, 'group' => 'general', 'is_public' => true, 'description' => 'Platform display name'],
            ['key' => 'site_tagline', 'value' => 'Your Starter Platform Tagline', 'value_type' => SettingValueType::String, 'group' => 'general', 'is_public' => true, 'description' => 'Short tagline shown in browser tabs and metadata'],
            ['key' => 'contact_email', 'value' => 'info@example.test', 'value_type' => SettingValueType::String, 'group' => 'general', 'is_public' => true, 'description' => 'Primary contact email'],
            ['key' => 'support_email', 'value' => 'support@example.test', 'value_type' => SettingValueType::String, 'group' => 'general', 'is_public' => true, 'description' => 'Customer support email'],

            // ── Home ─────────────────────────────────────────────────────────
            ['key' => 'hero_title', 'value' => 'Welcome', 'value_type' => SettingValueType::String, 'group' => 'home', 'is_public' => true, 'description' => 'Main hero heading on the home page'],
            ['key' => 'hero_subtitle', 'value' => 'A starter platform ready for your content', 'value_type' => SettingValueType::String, 'group' => 'home', 'is_public' => true, 'description' => 'Hero subheading'],
            ['key' => 'hero_description', 'value' => 'Default placeholder description text. Replace it with your own product copy.', 'value_type' => SettingValueType::Text, 'group' => 'home', 'is_public' => true, 'description' => 'Supporting description text in the hero section'],
            ['key' => 'hero_primary_cta_text', 'value' => 'Get Started', 'value_type' => SettingValueType::String, 'group' => 'home', 'is_public' => true, 'description' => 'Primary call-to-action button label in the hero section'],
            ['key' => 'hero_secondary_cta_text', 'value' => 'Learn More', 'value_type' => SettingValueType::String, 'group' => 'home', 'is_public' => true, 'description' => 'Secondary CTA button label in the hero section'],
            ['key' => 'how_it_works_title', 'value' => 'How It Works', 'value_type' => SettingValueType::String, 'group' => 'home', 'is_public' => true, 'description' => 'Section heading for the how-it-works steps'],
            ['key' => 'features_title', 'value' => 'Features', 'value_type' => SettingValueType::String, 'group' => 'home', 'is_public' => true, 'description' => 'Section heading for the features cards'],
            ['key' => 'testimonials_title', 'value' => 'What Our Users Say', 'value_type' => SettingValueType::String, 'group' => 'home', 'is_public' => true, 'description' => 'Heading for the testimonials section'],
            ['key' => 'cta_banner_title', 'value' => 'Ready to Get Started?', 'value_type' => SettingValueType::String, 'group' => 'home', 'is_public' => true, 'description' => 'CTA banner heading'],
            ['key' => 'cta_banner_description', 'value' => 'Default placeholder call-to-action copy. Replace it with your own.', 'value_type' => SettingValueType::Text, 'group' => 'home', 'is_public' => true, 'description' => 'Supporting text for the CTA banner'],
            ['key' => 'faq_title', 'value' => 'Frequently Asked Questions', 'value_type' => SettingValueType::String, 'group' => 'home', 'is_public' => true, 'description' => 'Heading for the FAQ section on the home page'],

            // ── Footer ───────────────────────────────────────────────────────
            ['key' => 'footer_copyright', 'value' => '© 2026 Starter Platform. All rights reserved.', 'value_type' => SettingValueType::String, 'group' => 'footer', 'is_public' => true, 'description' => 'Copyright notice displayed in the footer'],
            ['key' => 'footer_contact_email', 'value' => 'info@example.test', 'value_type' => SettingValueType::String, 'group' => 'footer', 'is_public' => true, 'description' => 'Contact email shown in the footer'],
            ['key' => 'footer_quick_links_title', 'value' => 'Quick Links', 'value_type' => SettingValueType::String, 'group' => 'footer', 'is_public' => true, 'description' => 'Heading for the quick links column in the footer'],
            ['key' => 'footer_customer_services_title', 'value' => 'Customer Services', 'value_type' => SettingValueType::String, 'group' => 'footer', 'is_public' => true, 'description' => 'Heading for the customer services column in the footer'],

            // ── Social ───────────────────────────────────────────────────────
            ['key' => 'social_twitter', 'value' => '', 'value_type' => SettingValueType::String, 'group' => 'social', 'is_public' => true, 'description' => 'Twitter/X profile URL'],
            ['key' => 'social_linkedin', 'value' => '', 'value_type' => SettingValueType::String, 'group' => 'social', 'is_public' => true, 'description' => 'LinkedIn profile URL'],
            ['key' => 'social_instagram', 'value' => '', 'value_type' => SettingValueType::String, 'group' => 'social', 'is_public' => true, 'description' => 'Instagram profile URL'],
            ['key' => 'social_facebook', 'value' => '', 'value_type' => SettingValueType::String, 'group' => 'social', 'is_public' => true, 'description' => 'Facebook page URL'],
            ['key' => 'social_youtube', 'value' => '', 'value_type' => SettingValueType::String, 'group' => 'social', 'is_public' => true, 'description' => 'YouTube channel URL'],
        ];

        foreach ($settings as $data) {
            Setting::updateOrCreate(
                ['key' => $data['key']],
                $data
            );
        }
    }
}
