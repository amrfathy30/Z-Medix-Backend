<?php

namespace App\Content;

use App\Support\Content\Definitions\ContentItemDefinition;
use App\Support\Content\Definitions\ContentSectionDefinition;
use App\Support\Content\Definitions\PageContentDefinition;
use App\Support\Content\Enums\ContentInputType;

/**
 * Starter Website Content structure. This class is the only place that
 * should know the concrete page/section/item shape for this starter — it
 * must never be imported by the generic Support\Content core, and it must
 * never reference product-specific business models. It only describes
 * structure and neutral placeholder copy for the Website Content feature;
 * a real product is expected to replace this copy via the Website Content
 * editor (or by extending this class) before launch.
 *
 * `default_data` on each section is the entire JSON payload written into
 * page_sections.data for a newly created row — including title/subtitle/
 * body, which moved out of their legacy root columns in Phase 3F. The
 * legacy title/subtitle/body columns still exist on the table (for
 * rollback/transition) but are no longer written by the seeder for new
 * rows; PageSectionResource and the public API both read/write
 * title/subtitle/body exclusively through `data` now.
 *
 * @return list<PageContentDefinition>
 */
class WebsiteContentDefinitions
{
    /** @return list<PageContentDefinition> */
    public static function all(): array
    {
        return [
            self::home(),
            self::aboutUs(),
            self::services(),
            self::privacyPolicy(),
            self::termsAndConditions(),
            self::contactUs(),
        ];
    }

    private static function home(): PageContentDefinition
    {
        return PageContentDefinition::make('home', 'Home', 'الرئيسية', [
            ContentSectionDefinition::make([
                'section_key' => 'hero',
                'label_en' => 'Hero',
                'label_ar' => 'القسم الرئيسي',
                'sort_order' => 1,
                'items' => [
                    self::title(1),
                    self::subtitle(2),
                    self::body(3),
                    ContentItemDefinition::make([
                        'key' => 'primary_cta_text',
                        'type' => ContentInputType::ShortText,
                        'label_en' => 'Primary CTA Text',
                        'label_ar' => 'نص الزر الأساسي',
                        'translatable' => true,
                        'sort_order' => 4,
                    ]),
                    ContentItemDefinition::make([
                        'key' => 'secondary_cta_text',
                        'type' => ContentInputType::ShortText,
                        'label_en' => 'Secondary CTA Text',
                        'label_ar' => 'نص الزر الثانوي',
                        'translatable' => true,
                        'sort_order' => 5,
                    ]),
                    self::backgroundImage(6),
                ],
                'default_data' => [
                    'title' => ['en' => 'Welcome', 'ar' => 'مرحباً'],
                    'subtitle' => [
                        'en' => 'A starter platform ready for your content',
                        'ar' => 'منصة أساسية جاهزة لمحتواك',
                    ],
                    'body' => [
                        'en' => 'This is default placeholder content for the home page hero. Replace it with your own product copy via the Website Content editor.',
                        'ar' => 'هذا محتوى افتراضي مؤقت لقسم البانر الرئيسي بالصفحة الرئيسية. استبدله بمحتوى منتجك عبر محرر محتوى الموقع.',
                    ],
                    'primary_cta_text' => ['en' => 'Get Started', 'ar' => 'ابدأ الآن'],
                    'secondary_cta_text' => ['en' => 'Learn More', 'ar' => 'اعرف أكثر'],
                ],
            ]),
            self::simpleContentSection('how_it_works', 'How It Works', 'كيف يعمل', 2, [
                'title' => ['en' => 'How It Works', 'ar' => 'كيف يعمل'],
                'body' => [
                    'en' => 'Default placeholder text describing how the platform works.',
                    'ar' => 'نص افتراضي مؤقت يوضح كيفية عمل المنصة.',
                ],
            ]),
            self::simpleContentSection('features', 'Features', 'الميزات', 3, [
                'title' => ['en' => 'Features', 'ar' => 'الميزات'],
                'body' => [
                    'en' => 'Default placeholder text describing the platform features.',
                    'ar' => 'نص افتراضي مؤقت يوضح ميزات المنصة.',
                ],
            ]),
            self::simpleContentSection('testimonials', 'Testimonials', 'آراء العملاء', 4, [
                'title' => ['en' => 'What Our Users Say', 'ar' => 'ماذا يقول مستخدمونا'],
            ]),
            ContentSectionDefinition::make([
                'section_key' => 'cta_banner',
                'label_en' => 'CTA Banner',
                'label_ar' => 'بانر دعوة لاتخاذ إجراء',
                'sort_order' => 5,
                'items' => [
                    self::title(1),
                    self::body(2),
                    self::cta(3),
                ],
                'default_data' => [
                    'title' => ['en' => 'Ready to Get Started?', 'ar' => 'هل أنت مستعد للبدء؟'],
                    'body' => [
                        'en' => 'Default placeholder call-to-action copy. Replace it with your own.',
                        'ar' => 'نص دعوة لاتخاذ إجراء افتراضي مؤقت. استبدله بمحتواك الخاص.',
                    ],
                ],
            ]),
            self::simpleContentSection('faq', 'FAQ', 'الأسئلة الشائعة', 6, [
                'title' => ['en' => 'Frequently Asked Questions', 'ar' => 'الأسئلة الشائعة'],
            ]),
        ]);
    }

    private static function aboutUs(): PageContentDefinition
    {
        return PageContentDefinition::make('about-us', 'About Us', 'من نحن', [
            self::heroSection(1, [
                'title' => ['en' => 'About Us', 'ar' => 'من نحن'],
                'body' => [
                    'en' => 'Default placeholder content. Replace it with information about your product or organization.',
                    'ar' => 'محتوى افتراضي مؤقت. استبدله بمعلومات عن منتجك أو مؤسستك.',
                ],
            ]),
            self::simpleContentSection('mission', 'Mission', 'مهمتنا', 2, [
                'title' => ['en' => 'Our Mission', 'ar' => 'مهمتنا'],
                'body' => [
                    'en' => 'Default placeholder mission statement.',
                    'ar' => 'بيان مهمة افتراضي مؤقت.',
                ],
            ]),
            self::simpleContentSection('vision', 'Vision', 'رؤيتنا', 3, [
                'title' => ['en' => 'Our Vision', 'ar' => 'رؤيتنا'],
                'body' => [
                    'en' => 'Default placeholder vision statement.',
                    'ar' => 'بيان رؤية افتراضي مؤقت.',
                ],
            ]),
        ]);
    }

    /**
     * Generic "what we offer" page — replaces any product-specific
     * recruitment/pricing page from a prior product. Demonstrates the
     * repeater and CTA-composite item types for the Website Content
     * mechanism using neutral placeholder copy.
     */
    private static function services(): PageContentDefinition
    {
        return PageContentDefinition::make('services', 'Services', 'الخدمات', [
            self::heroSection(1, [
                'title' => ['en' => 'Our Services', 'ar' => 'خدماتنا'],
                'body' => [
                    'en' => 'Default placeholder content describing what is offered.',
                    'ar' => 'محتوى افتراضي مؤقت يصف ما يتم تقديمه.',
                ],
            ]),
            ContentSectionDefinition::make([
                'section_key' => 'benefits',
                'label_en' => 'Benefits',
                'label_ar' => 'المزايا',
                'sort_order' => 2,
                'items' => [
                    self::title(1),
                    self::itemsRepeater(2, 'Benefits', 'المزايا'),
                ],
                'default_data' => [
                    'title' => ['en' => 'Why Choose Us?', 'ar' => 'لماذا تختارنا؟'],
                    'items' => [
                        ['en' => 'Default placeholder benefit one', 'ar' => 'ميزة افتراضية أولى'],
                        ['en' => 'Default placeholder benefit two', 'ar' => 'ميزة افتراضية ثانية'],
                        ['en' => 'Default placeholder benefit three', 'ar' => 'ميزة افتراضية ثالثة'],
                    ],
                ],
            ]),
            ContentSectionDefinition::make([
                'section_key' => 'cta',
                'label_en' => 'CTA',
                'label_ar' => 'دعوة لاتخاذ إجراء',
                'sort_order' => 3,
                'items' => [
                    self::title(1),
                    self::body(2),
                    self::cta(3),
                ],
                'default_data' => [
                    'title' => ['en' => 'Get Started Today', 'ar' => 'ابدأ اليوم'],
                    'body' => [
                        'en' => 'Default placeholder call-to-action copy.',
                        'ar' => 'نص دعوة لاتخاذ إجراء افتراضي مؤقت.',
                    ],
                ],
            ]),
        ]);
    }

    /**
     * Since Phase 3G, privacy-policy is a normal Website Content page with a
     * single `content` section — default_data mirrors the content that
     * previously lived in CmsSeeder's pages.content/meta_* for this slug.
     */
    private static function privacyPolicy(): PageContentDefinition
    {
        return PageContentDefinition::make('privacy-policy', 'Privacy Policy', 'سياسة الخصوصية', [
            self::staticContentSection([
                'title' => ['en' => 'Privacy Policy', 'ar' => 'سياسة الخصوصية'],
                'body' => [
                    'en' => '<h2>Privacy Policy</h2><p>This is default placeholder content. Replace it with your own privacy policy before launch.</p><h3>Information We Collect</h3><p>We collect information you provide directly, such as your name, email address, and usage data.</p><h3>How We Use Your Information</h3><p>We use your information to provide and improve our services, communicate with you, and ensure platform security.</p><h3>Data Protection</h3><p>We implement industry-standard security measures to protect your personal data.</p><h3>Contact Us</h3><p>For privacy-related inquiries, please contact us at privacy@example.test.</p>',
                    'ar' => '<h2>سياسة الخصوصية</h2><p>هذا محتوى افتراضي مؤقت. يرجى استبداله بسياسة الخصوصية الخاصة بك قبل الإطلاق.</p><h3>المعلومات التي نجمعها</h3><p>نجمع المعلومات التي تقدمها مباشرة، مثل اسمك وعنوان بريدك الإلكتروني وبيانات الاستخدام.</p><h3>كيف نستخدم معلوماتك</h3><p>نستخدم معلوماتك لتقديم خدماتنا وتحسينها والتواصل معك وضمان أمان المنصة.</p><h3>حماية البيانات</h3><p>نطبق تدابير أمنية بمعايير الصناعة لحماية بياناتك الشخصية.</p><h3>اتصل بنا</h3><p>للاستفسارات المتعلقة بالخصوصية، يرجى التواصل معنا على privacy@example.test.</p>',
                ],
                'meta_title' => ['en' => 'Privacy Policy', 'ar' => 'سياسة الخصوصية'],
                'meta_description' => [
                    'en' => 'Default placeholder meta description for the Privacy Policy page.',
                    'ar' => 'وصف ميتا افتراضي مؤقت لصفحة سياسة الخصوصية.',
                ],
            ]),
        ]);
    }

    /**
     * Since Phase 3G, terms-and-conditions is a normal Website Content page
     * with a single `content` section — see privacyPolicy() note.
     */
    private static function termsAndConditions(): PageContentDefinition
    {
        return PageContentDefinition::make('terms-and-conditions', 'Terms and Conditions', 'الشروط والأحكام', [
            self::staticContentSection([
                'title' => ['en' => 'Terms and Conditions', 'ar' => 'الشروط والأحكام'],
                'body' => [
                    'en' => '<h2>Terms and Conditions</h2><p>This is default placeholder content. Replace it with your own terms and conditions before launch.</p><h3>Acceptance of Terms</h3><p>Access to and use of our platform is conditioned on your acceptance of these terms.</p><h3>Use of Service</h3><p>You must be at least 18 years old to use this service. You are responsible for maintaining the confidentiality of your account.</p><h3>Intellectual Property</h3><p>The platform and its original content are and will remain the exclusive property of their owner.</p><h3>Limitation of Liability</h3><p>The platform owner shall not be liable for any indirect, incidental, or consequential damages arising from your use of the service.</p><h3>Changes to Terms</h3><p>We reserve the right to modify these terms at any time. We will provide notice of significant changes.</p>',
                    'ar' => '<h2>الشروط والأحكام</h2><p>هذا محتوى افتراضي مؤقت. يرجى استبداله بالشروط والأحكام الخاصة بك قبل الإطلاق.</p><h3>قبول الشروط</h3><p>يخضع الوصول إلى منصتنا واستخدامها لقبولك لهذه الشروط.</p><h3>استخدام الخدمة</h3><p>يجب أن يكون عمرك 18 عامًا على الأقل لاستخدام هذه الخدمة. أنت مسؤول عن الحفاظ على سرية حسابك.</p><h3>الملكية الفكرية</h3><p>المنصة ومحتواها الأصلي هي وستبقى ملكية حصرية لمالكها.</p><h3>تحديد المسؤولية</h3><p>لن يكون مالك المنصة مسؤولاً عن أي أضرار غير مباشرة أو عرضية أو تبعية ناجمة عن استخدامك للخدمة.</p><h3>التغييرات على الشروط</h3><p>نحتفظ بالحق في تعديل هذه الشروط في أي وقت. سنقدم إشعارًا بالتغييرات الجوهرية.</p>',
                ],
                'meta_title' => ['en' => 'Terms and Conditions', 'ar' => 'الشروط والأحكام'],
                'meta_description' => [
                    'en' => 'Default placeholder meta description for the Terms and Conditions page.',
                    'ar' => 'وصف ميتا افتراضي مؤقت لصفحة الشروط والأحكام.',
                ],
            ]),
        ]);
    }

    /**
     * Default placeholder content — admins are expected to replace this via
     * the Website Content editor before launch.
     */
    private static function contactUs(): PageContentDefinition
    {
        return PageContentDefinition::make('contact-us', 'Contact Us', 'اتصل بنا', [
            self::staticContentSection([
                'title' => ['en' => 'Contact Us', 'ar' => 'اتصل بنا'],
                'body' => [
                    'en' => '<p>This is default placeholder content. Replace it with your contact details and any introductory text you would like visitors to see.</p>',
                    'ar' => '<p>هذا محتوى افتراضي مؤقت. يرجى استبداله ببيانات التواصل والنص التعريفي الذي تريد أن يراه الزوار.</p>',
                ],
                'meta_title' => ['en' => 'Contact Us', 'ar' => 'اتصل بنا'],
                'meta_description' => [
                    'en' => 'Default placeholder meta description for the Contact Us page.',
                    'ar' => 'وصف ميتا افتراضي مؤقت لصفحة اتصل بنا.',
                ],
            ]),
        ]);
    }

    /**
     * Single-section shape shared by the static pages: title, rich body,
     * and meta title/description — all translatable, all stored in `data`.
     *
     * @param  array<string, mixed>  $defaultData
     */
    private static function staticContentSection(array $defaultData): ContentSectionDefinition
    {
        return ContentSectionDefinition::make([
            'section_key' => 'content',
            'label_en' => 'Content',
            'label_ar' => 'المحتوى',
            'sort_order' => 1,
            'items' => [
                self::title(1),
                ContentItemDefinition::make([
                    'key' => 'body',
                    'type' => ContentInputType::RichText,
                    'label_en' => 'Body',
                    'label_ar' => 'النص',
                    'translatable' => true,
                    'sort_order' => 2,
                ]),
                ContentItemDefinition::make([
                    'key' => 'meta_title',
                    'type' => ContentInputType::ShortText,
                    'label_en' => 'Meta Title',
                    'label_ar' => 'عنوان الميتا',
                    'translatable' => true,
                    'sort_order' => 3,
                ]),
                ContentItemDefinition::make([
                    'key' => 'meta_description',
                    'type' => ContentInputType::LongText,
                    'label_en' => 'Meta Description',
                    'label_ar' => 'وصف الميتا',
                    'translatable' => true,
                    'sort_order' => 4,
                ]),
            ],
            'default_data' => $defaultData,
        ]);
    }

    /** @param  array<string, mixed>  $defaultData  Merged on top of the structural default attribute payload. */
    private static function simpleContentSection(string $sectionKey, string $labelEn, string $labelAr, int $sortOrder, array $defaultData): ContentSectionDefinition
    {
        return ContentSectionDefinition::make([
            'section_key' => $sectionKey,
            'label_en' => $labelEn,
            'label_ar' => $labelAr,
            'sort_order' => $sortOrder,
            'items' => [
                self::title(1),
                self::body(2),
            ],
            'default_data' => $defaultData,
        ]);
    }

    private static function title(int $sortOrder): ContentItemDefinition
    {
        return ContentItemDefinition::make([
            'key' => 'title',
            'type' => ContentInputType::ShortText,
            'label_en' => 'Title (English)',
            'label_ar' => 'العنوان (عربي)',
            'translatable' => true,
            'sort_order' => $sortOrder,
        ]);
    }

    private static function subtitle(int $sortOrder): ContentItemDefinition
    {
        return ContentItemDefinition::make([
            'key' => 'subtitle',
            'type' => ContentInputType::ShortText,
            'label_en' => 'Subtitle (English)',
            'label_ar' => 'العنوان الفرعي (عربي)',
            'translatable' => true,
            'sort_order' => $sortOrder,
        ]);
    }

    private static function body(int $sortOrder): ContentItemDefinition
    {
        return ContentItemDefinition::make([
            'key' => 'body',
            'type' => ContentInputType::LongText,
            'label_en' => 'Body (English)',
            'label_ar' => 'النص (عربي)',
            'translatable' => true,
            'sort_order' => $sortOrder,
        ]);
    }

    private static function itemsRepeater(int $sortOrder, string $labelEn = 'Items', string $labelAr = 'العناصر'): ContentItemDefinition
    {
        return ContentItemDefinition::make([
            'key' => 'items',
            'type' => ContentInputType::Repeater,
            'label_en' => $labelEn,
            'label_ar' => $labelAr,
            'sort_order' => $sortOrder,
            'schema' => [
                ContentItemDefinition::make([
                    'key' => 'label',
                    'type' => ContentInputType::ShortText,
                    'label_en' => 'Label (English)',
                    'label_ar' => 'التسمية (عربي)',
                    'translatable' => true,
                ]),
            ],
        ]);
    }

    /**
     * Maps directly onto the PageSection model's `background` media
     * collection (see PageSection::registerMediaCollections()) — the item
     * key intentionally matches the collection name so ContentInputFactory
     * can bind a SpatieMediaLibraryFileUpload to it without extra config.
     */
    private static function backgroundImage(int $sortOrder): ContentItemDefinition
    {
        return ContentItemDefinition::make([
            'key' => 'background',
            'type' => ContentInputType::Image,
            'label_en' => 'Background Image',
            'label_ar' => 'صورة الخلفية',
            'sort_order' => $sortOrder,
        ]);
    }

    private static function cta(int $sortOrder): ContentItemDefinition
    {
        return ContentItemDefinition::make([
            'key' => 'cta',
            'type' => ContentInputType::Cta,
            'label_en' => 'Call to Action',
            'label_ar' => 'دعوة لاتخاذ إجراء',
            'sort_order' => $sortOrder,
        ]);
    }

    /** @param  array<string, mixed>  $defaultData  Merged on top of title/body defaults for the hero section. */
    private static function heroSection(int $sortOrder, array $defaultData): ContentSectionDefinition
    {
        return ContentSectionDefinition::make([
            'section_key' => 'hero',
            'label_en' => 'Hero',
            'label_ar' => 'القسم الرئيسي',
            'sort_order' => $sortOrder,
            'items' => [
                self::title(1),
                self::body(2),
                self::backgroundImage(3),
            ],
            'default_data' => $defaultData,
        ]);
    }
}
