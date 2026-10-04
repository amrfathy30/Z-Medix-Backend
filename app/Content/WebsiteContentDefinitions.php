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

    /**
     * Landing page (design/Landing Page.png). Section keys, item keys and the
     * repeater row keys below are the contract with the frontend — see
     * docs/website-content-dashboard-guide.md before renaming any of them.
     */
    private static function home(): PageContentDefinition
    {
        return PageContentDefinition::make('home', 'Home', 'الرئيسية', [
            self::homeHero(),
            self::homeFeatures(),
            self::homePlans(),
            self::homeAiAssistant(),
            self::homeOnMobile(),
        ], seo: [
            'meta_title' => [
                'en' => 'Z-MEDIX — AI Study Assistant for Medical Students',
                'ar' => 'Z-MEDIX — مساعدك الذكي لدراسة الطب',
            ],
            'meta_description' => [
                'en' => 'Upload your medical books, ask questions, practice with a question bank and track your progress with Z-MEDIX, the AI study assistant built for medical students.',
                'ar' => 'ارفع كتبك الطبية واسأل وتدرّب على بنك الأسئلة وتابع تقدمك مع Z-MEDIX، مساعد الدراسة الذكي المصمم لطلاب الطب.',
            ],
        ]);
    }

    private static function homeHero(): ContentSectionDefinition
    {
        return ContentSectionDefinition::make([
            'section_key' => 'hero',
            'label_en' => 'Hero',
            'label_ar' => 'القسم الرئيسي',
            'sort_order' => 1,
            'items' => [
                self::title(1),
                self::description(2),
                self::image('image', 'Hero Image', 'صورة القسم الرئيسي', 3),
                self::image('image_small', 'Small Hero Image', 'صورة القسم الرئيسي الصغيرة', 4),
            ],
            'default_data' => [
                'title' => ['en' => 'Manage Your Health Anytime, Anywhere.', 'ar' => 'أدر صحتك في أي وقت ومن أي مكان.'],
                'description' => [
                    'en' => 'Z-MEDIX helps you book appointments, track wellness, consult doctors, and manage reports — all in one simple app.',
                    'ar' => 'يساعدك Z-MEDIX على حجز المواعيد وتتبع صحتك واستشارة الأطباء وإدارة تقاريرك — كل ذلك في تطبيق واحد بسيط.',
                ],
            ],
        ]);
    }

    private static function homeFeatures(): ContentSectionDefinition
    {
        return ContentSectionDefinition::make([
            'section_key' => 'features',
            'label_en' => 'Our Features',
            'label_ar' => 'ميزاتنا',
            'sort_order' => 2,
            'items' => [
                self::title(1),
                self::description(2),
                self::repeater('features', 'Features', 'الميزات', 3, [
                    self::title(1),
                    self::description(2),
                    self::image('icon', 'Icon', 'الأيقونة', 3),
                ], ['max_items' => 6, 'item_label' => 'title']),
            ],
            'default_data' => [
                'title' => ['en' => 'Our Features', 'ar' => 'ميزاتنا'],
                'description' => [
                    'en' => 'Everything you need to master your medical studies, powered by advanced AI.',
                    'ar' => 'كل ما تحتاجه لإتقان دراستك الطبية، بدعم من الذكاء الاصطناعي المتقدم.',
                ],
                'features' => [
                    self::featureRow(
                        'AI Study Assistant', 'مساعد الدراسة الذكي',
                        'Ask, understand, and learn with an AI assistant that helps you study smarter.',
                        'اسأل وافهم وتعلّم مع مساعد ذكي يساعدك على الدراسة بطريقة أذكى.',
                    ),
                    self::featureRow(
                        'Learn From Your Books', 'تعلّم من كتبك',
                        'Upload and organize your medical books and PDFs in one place.',
                        'ارفع كتبك الطبية وملفات PDF ونظّمها في مكان واحد.',
                    ),
                    self::featureRow(
                        'Your Medical Library', 'مكتبتك الطبية',
                        'Let AI explain, summarize, and help you study directly from your uploaded content.',
                        'دع الذكاء الاصطناعي يشرح ويلخّص ويساعدك على الدراسة مباشرة من المحتوى الذي رفعته.',
                    ),
                    self::featureRow(
                        'Question Bank', 'بنك الأسئلة',
                        'Practice questions anytime with different difficulty levels and chapter-based filters.',
                        'تدرّب على الأسئلة في أي وقت بمستويات صعوبة مختلفة وفلاتر حسب الفصول.',
                    ),
                    self::featureRow(
                        'Notes & Highlights', 'الملاحظات والتظليل',
                        'Save important information, highlight key concepts, and build your own study notes.',
                        'احفظ المعلومات المهمة وظلّل المفاهيم الأساسية وكوّن ملاحظات دراستك الخاصة.',
                    ),
                    self::featureRow(
                        'Track Your Progress', 'تابع تقدمك',
                        'Monitor your chapters, quizzes, scores, and learning progress as you move forward.',
                        'راقب فصولك واختباراتك ودرجاتك وتقدمك التعليمي أثناء تقدمك.',
                    ),
                ],
            ],
        ]);
    }

    /**
     * `plans` holds every plan of every billing cycle: the frontend filters
     * by `billing_type` when the Monthly/Yearly toggle changes (3 plans each).
     */
    private static function homePlans(): ContentSectionDefinition
    {
        return ContentSectionDefinition::make([
            'section_key' => 'plans',
            'label_en' => 'Plans',
            'label_ar' => 'الباقات',
            'sort_order' => 3,
            'items' => [
                self::title(1),
                self::description(2),
                self::repeater('plans', 'Plans', 'الباقات', 3, [
                    self::title(1),
                    ContentItemDefinition::make([
                        'key' => 'price',
                        'type' => ContentInputType::Money,
                        'label_en' => 'Price',
                        'label_ar' => 'السعر',
                        'sort_order' => 2,
                    ]),
                    ContentItemDefinition::make([
                        'key' => 'discount',
                        'type' => ContentInputType::Percentage,
                        'label_en' => 'Discount (optional)',
                        'label_ar' => 'الخصم (اختياري)',
                        'sort_order' => 3,
                    ]),
                    ContentItemDefinition::make([
                        'key' => 'billing_type',
                        'type' => ContentInputType::Select,
                        'label_en' => 'Billing Type',
                        'label_ar' => 'نوع الفوترة',
                        'sort_order' => 4,
                        'settings' => ['options' => ['monthly' => 'Monthly', 'yearly' => 'Yearly']],
                    ]),
                    self::repeater('features', 'Plan Features', 'مزايا الباقة', 5, [
                        ContentItemDefinition::make([
                            'key' => 'label',
                            'type' => ContentInputType::ShortText,
                            'label_en' => 'Feature (English)',
                            'label_ar' => 'الميزة (عربي)',
                            'translatable' => true,
                        ]),
                    ], ['item_label' => 'label']),
                    ContentItemDefinition::make([
                        'key' => 'background_color',
                        'type' => ContentInputType::Color,
                        'label_en' => 'Background Color',
                        'label_ar' => 'لون الخلفية',
                        'sort_order' => 6,
                    ]),
                    self::image('icon', 'Icon', 'الأيقونة', 7),
                    ContentItemDefinition::make([
                        'key' => 'icon_color',
                        'type' => ContentInputType::Color,
                        'label_en' => 'Icon Color',
                        'label_ar' => 'لون الأيقونة',
                        'sort_order' => 8,
                    ]),
                ], ['max_items' => 6, 'item_label' => 'title']),
            ],
            'default_data' => [
                'title' => ['en' => 'Get Premium Access', 'ar' => 'احصل على الوصول المميز'],
                'description' => [
                    'en' => 'Unlock the full Z-MEDIX learning experience with AI-powered study tools, high-fidelity audio options, and an ad-free environment.',
                    'ar' => 'افتح تجربة التعلّم الكاملة في Z-MEDIX مع أدوات دراسة مدعومة بالذكاء الاصطناعي وخيارات صوتية عالية الدقة وبيئة خالية من الإعلانات.',
                ],
                // Yearly prices/discounts are placeholders — the design only shows the monthly toggle state.
                'plans' => [
                    self::planRow('Basic Plan', 'الباقة الأساسية', 5.99, null, 'monthly', '#C3BFFE', '#F7A041'),
                    self::planRow('Premium Plan', 'الباقة المميزة', 9.99, null, 'monthly', '#039DA7', '#FFC136'),
                    self::planRow('Ultra Plus Plan', 'باقة ألترا بلس', 25.99, null, 'monthly', '#E1F99C', '#0F1113'),
                    self::planRow('Basic Plan', 'الباقة الأساسية', 59.99, 20, 'yearly', '#C3BFFE', '#F7A041'),
                    self::planRow('Premium Plan', 'الباقة المميزة', 99.99, 20, 'yearly', '#039DA7', '#FFC136'),
                    self::planRow('Ultra Plus Plan', 'باقة ألترا بلس', 259.99, 20, 'yearly', '#E1F99C', '#0F1113'),
                ],
            ],
        ]);
    }

    private static function homeAiAssistant(): ContentSectionDefinition
    {
        return ContentSectionDefinition::make([
            'section_key' => 'ai_assistant',
            'label_en' => 'AI Assistant',
            'label_ar' => 'المساعد الذكي',
            'sort_order' => 4,
            'items' => [
                self::subtitle(1),
                self::title(2),
                self::description(3),
                self::image('image', 'Main Image', 'الصورة الرئيسية', 4),
                ContentItemDefinition::make([
                    'key' => 'supporting_images',
                    'type' => ContentInputType::MultiImage,
                    'label_en' => 'Supporting Images (4)',
                    'label_ar' => 'الصور الداعمة (4)',
                    'sort_order' => 5,
                    'settings' => ['collection' => 'gallery', 'max_files' => 4],
                ]),
                self::image('logo', 'Small Logo', 'الشعار الصغير', 6),
                self::repeater('highlights', 'Highlights', 'النقاط البارزة', 7, [
                    self::title(1),
                    self::image('icon', 'Icon', 'الأيقونة', 2),
                ], ['max_items' => 3, 'item_label' => 'title']),
            ],
            'default_data' => [
                'subtitle' => ['en' => 'AI-Powered Learning', 'ar' => 'التعلّم المدعوم بالذكاء الاصطناعي'],
                'title' => ['en' => 'Meet Your AI Study Assistant', 'ar' => 'تعرّف على مساعدك الذكي للدراسة'],
                'description' => [
                    'en' => "Ask, understand, summarize, and practice — all with an AI assistant that learns from the content you're studying.",
                    'ar' => 'اسأل وافهم ولخّص وتدرّب — كل ذلك مع مساعد ذكي يتعلّم من المحتوى الذي تدرسه.',
                ],
                'highlights' => [
                    ['title' => ['en' => 'Upload a book or PDF.', 'ar' => 'ارفع كتابًا أو ملف PDF.'], 'icon' => null],
                    ['title' => ['en' => 'Ask questions about the content.', 'ar' => 'اطرح أسئلة حول المحتوى.'], 'icon' => null],
                    ['title' => ['en' => 'Get explanations, summaries, quizzes, and flashcards.', 'ar' => 'احصل على شروحات وملخصات واختبارات وبطاقات تعليمية.'], 'icon' => null],
                ],
            ],
        ]);
    }

    /**
     * The App Store / Google Play links are not edited here: the public API fills
     * `data.app_store_url` and `data.google_play_url` from the `app_store_url` and
     * `google_play_url` Site Settings (see Api\Public\PageSectionResource).
     */
    private static function homeOnMobile(): ContentSectionDefinition
    {
        return ContentSectionDefinition::make([
            'section_key' => 'on_mobile',
            'label_en' => 'On Mobile',
            'label_ar' => 'على الجوال',
            'sort_order' => 5,
            'items' => [
                self::subtitle(1),
                self::title(2),
                self::description(3),
                self::image('image', 'Image', 'الصورة', 4),
            ],
            'default_data' => [
                'subtitle' => ['en' => 'On Mobile', 'ar' => 'على الجوال'],
                'title' => ['en' => 'The AI Study Partner Every Med Student Needs', 'ar' => 'شريك الدراسة الذكي الذي يحتاجه كل طالب طب'],
                'description' => [
                    'en' => 'Take your learning with you wherever you go. Study, practice, and learn on the move.',
                    'ar' => 'خذ تعلّمك معك أينما ذهبت. ادرس وتدرّب وتعلّم أثناء التنقل.',
                ],
            ],
        ]);
    }

    /** @return array<string, mixed> One default `features` repeater row; the icon is uploaded from the dashboard. */
    private static function featureRow(string $titleEn, string $titleAr, string $descriptionEn, string $descriptionAr): array
    {
        return [
            'title' => ['en' => $titleEn, 'ar' => $titleAr],
            'description' => ['en' => $descriptionEn, 'ar' => $descriptionAr],
            'icon' => null,
        ];
    }

    /** @return array<string, mixed> One default `plans` repeater row. */
    private static function planRow(string $titleEn, string $titleAr, float $price, ?int $discount, string $billingType, string $backgroundColor, string $iconColor): array
    {
        return [
            'title' => ['en' => $titleEn, 'ar' => $titleAr],
            'price' => $price,
            'discount' => $discount,
            'billing_type' => $billingType,
            'features' => [
                ['label' => ['en' => 'Block explicit music', 'ar' => 'حظر الموسيقى الصريحة']],
                ['label' => ['en' => 'Background music play', 'ar' => 'تشغيل الموسيقى في الخلفية']],
                ['label' => ['en' => 'Ads Remove', 'ar' => 'إزالة الإعلانات']],
                ['label' => ['en' => 'Premium audio quality', 'ar' => 'جودة صوت متميزة']],
            ],
            'background_color' => $backgroundColor,
            'icon' => null,
            'icon_color' => $iconColor,
        ];
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

    private static function description(int $sortOrder): ContentItemDefinition
    {
        return ContentItemDefinition::make([
            'key' => 'description',
            'type' => ContentInputType::LongText,
            'label_en' => 'Description (English)',
            'label_ar' => 'الوصف (عربي)',
            'translatable' => true,
            'sort_order' => $sortOrder,
        ]);
    }

    /**
     * Single image. At the top level of a section it maps onto the PageSection
     * media collection named after `$key` (or `settings['collection']`); inside
     * a repeater's schema it is a plain upload whose path is stored in the row.
     *
     * @param  array<string, mixed>  $settings
     */
    private static function image(string $key, string $labelEn, string $labelAr, int $sortOrder, array $settings = []): ContentItemDefinition
    {
        return ContentItemDefinition::make([
            'key' => $key,
            'type' => ContentInputType::Image,
            'label_en' => $labelEn,
            'label_ar' => $labelAr,
            'sort_order' => $sortOrder,
            'settings' => $settings,
        ]);
    }

    /**
     * @param  list<ContentItemDefinition>  $schema
     * @param  array<string, mixed>  $settings  `max_items`, `min_items` and `item_label` (a schema key) — see ContentInputFactory::makeRepeater().
     */
    private static function repeater(string $key, string $labelEn, string $labelAr, int $sortOrder, array $schema, array $settings = []): ContentItemDefinition
    {
        return ContentItemDefinition::make([
            'key' => $key,
            'type' => ContentInputType::Repeater,
            'label_en' => $labelEn,
            'label_ar' => $labelAr,
            'sort_order' => $sortOrder,
            'schema' => $schema,
            'settings' => $settings,
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
