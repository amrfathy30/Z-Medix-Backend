# Website Content Dashboard Guide

## 1. Overview

This starter includes a reusable Website Content system for managing public website content from the admin dashboard.

The system is definition-driven:

- Developers define pages, sections, and content inputs in code.
- A seeder reads those definitions.
- The seeder creates/syncs `Page` and `PageSection` records.
- Filament generates dashboard edit forms from the content input definitions.
- Admin users edit content from the dashboard.
- The public API returns the saved content to the frontend.

**Important:** This system generates database records and dashboard form inputs. It does not auto-code frontend components. The frontend still needs to render each section based on `section_key` and the returned `data`.

## 2. Main files

| File | Responsibility |
| --- | --- |
| `app/Content/WebsiteContentDefinitions.php` | The main place where default website pages, design sections, and editable content inputs are defined. |
| `app/Support/Content/Definitions/PageContentDefinition.php` | Represents one page definition. |
| `app/Support/Content/Definitions/ContentSectionDefinition.php` | Represents one section inside a page. |
| `app/Support/Content/Definitions/ContentItemDefinition.php` | Represents one editable content input inside a section. |
| `app/Support/Content/Definitions/ContentDefinitionRegistry.php` | Loads all page definitions and allows other parts of the system to read them. |
| `app/Support/Content/Forms/ContentInputFactory.php` | Converts content item definitions into Filament form inputs. |
| `app/Support/Content/Validation/ContentInputValidationMapper.php` | Converts content item definitions into validation rules. |
| `database/seeders/PageSectionSeeder.php` | Creates/syncs pages and sections from definitions without overwriting admin-edited content. |
| `app/Filament/Pages/WebsiteContentPage.php` | Shows website page cards in the dashboard. |
| `app/Filament/Pages/WebsiteContentSectionsPage.php` | Shows the sections of a selected page. |
| `app/Filament/Resources/PageSectionResource.php` | Lets admins edit section content using generated form inputs. |
| `app/Http/Controllers/Api/Public/PageController.php` | Public API for pages. |
| `app/Http/Controllers/Api/Public/PageSectionController.php` | Public API for page sections. |

The `ContentDefinitionRegistry` singleton is populated from `WebsiteContentDefinitions::all()` in `App\Providers\AppServiceProvider`.

## 3. Core concepts

**Page**
A public website page, such as `home`, `about-us`, `services`, `privacy-policy`, `terms-and-conditions`, `contact-us`.

**Section**
A design/content block inside a page, such as `hero`, `features`, `cta_banner`, `testimonials`, `content`.

**Content input/item**
A field inside a section that the admin can edit, such as title, subtitle, body, button text, image, background, or a repeater of items.

**Page key**
A stable identifier for the page. Example: `home`. Stored on `pages.key`.

**Section key**
A stable identifier for the section. Example: `hero`. Stored on `page_sections.section_key`.

**Content item key**
A stable identifier for a specific input inside the section. Example: `title`.

**Content data**
The saved section content. It is stored as JSON in `page_sections.data`.

**Media fields**
For media content items (`image`, `multi_image`, `file`, `video_upload`, `audio_upload`), the item key is also used as the Spatie Media Library collection name (unless overridden by `settings['collection']`).

## 4. How page/section generation works

1. A developer defines pages and sections in `WebsiteContentDefinitions.php`.
2. The developer defines the content inputs needed for each design section.
3. `PageSectionSeeder` reads the definitions via `ContentDefinitionRegistry`.
4. Missing `Page` rows are created (looked up by `key`).
5. Missing `PageSection` rows are created (looked up by `page_id` + `section_key`).
6. Default content (`default_data`) is written only when a section row is first created.
7. Existing admin-edited content is not overwritten — only structural metadata (`label`, `sort_order`) is refreshed on later runs.
8. The dashboard's `WebsiteContentPage` cards and `WebsiteContentSectionsPage` screens read the definitions/sections.
9. `PageSectionResource::form()` generates edit forms using `ContentInputFactory` from the content item definitions.
10. Admin edits the content in the dashboard.
11. The public API (`PageController`, `PageSectionController`) returns the updated section data.
12. The frontend renders the section based on `section_key`.

## 5. How to add a new dashboard-managed page

Example: add a new page called `partners`.

1. Open `app/Content/WebsiteContentDefinitions.php`.
2. Add a new private static method (e.g. `partners()`) that returns a `PageContentDefinition::make('partners', 'Partners', 'شركاؤنا', [...])` with a stable page key: `partners`.
3. Add English and Arabic page labels (the second and third arguments to `PageContentDefinition::make`).
4. Add one or more `ContentSectionDefinition::make([...])` section definitions with a `section_key`, `label_en`, `label_ar`, `sort_order`, and `items`.
5. Inside each section's `items`, add `ContentItemDefinition::make([...])` entries matching the design.
6. Add a `default_data` array on each section if you want default placeholder content written on first creation.
7. Add the new page to `self::all()` in `WebsiteContentDefinitions.php` so the registry picks it up.
8. `WebsiteContentPage::getCards()` currently lists page cards explicitly — add a `$this->sectionCard('partners', __('...'))` entry there, and add `partners` to `WebsiteContentSectionsPage::PAGE_TITLES`, so the new page appears in the dashboard.
9. Add `'partners'` to `PageSectionSeeder::DESIGNED_PAGE_KEYS` so the seeder seeds it.
10. Run the seeder.
11. Open the dashboard and confirm the page card appears.
12. Open the page and confirm its sections appear.
13. Edit and save content.
14. Test the public API.

Example definition, based on the real current API used in `WebsiteContentDefinitions.php`:

```php
use App\Support\Content\Definitions\ContentItemDefinition;
use App\Support\Content\Definitions\ContentSectionDefinition;
use App\Support\Content\Definitions\PageContentDefinition;
use App\Support\Content\Enums\ContentInputType;

private static function partners(): PageContentDefinition
{
    return PageContentDefinition::make('partners', 'Partners', 'شركاؤنا', [
        ContentSectionDefinition::make([
            'section_key' => 'hero',
            'label_en' => 'Hero',
            'label_ar' => 'القسم الرئيسي',
            'sort_order' => 1,
            'items' => [
                ContentItemDefinition::make([
                    'key' => 'title',
                    'type' => ContentInputType::ShortText,
                    'label_en' => 'Title (English)',
                    'label_ar' => 'العنوان (عربي)',
                    'translatable' => true,
                    'sort_order' => 1,
                ]),
                ContentItemDefinition::make([
                    'key' => 'body',
                    'type' => ContentInputType::LongText,
                    'label_en' => 'Body (English)',
                    'label_ar' => 'النص (عربي)',
                    'translatable' => true,
                    'sort_order' => 2,
                ]),
                ContentItemDefinition::make([
                    'key' => 'background',
                    'type' => ContentInputType::Image,
                    'label_en' => 'Background Image',
                    'label_ar' => 'صورة الخلفية',
                    'sort_order' => 3,
                ]),
            ],
            'default_data' => [
                'title' => ['en' => 'Our Partners', 'ar' => 'شركاؤنا'],
                'body' => [
                    'en' => 'Default placeholder partners introduction.',
                    'ar' => 'نص افتراضي مؤقت لتعريف الشركاء.',
                ],
            ],
        ]),
    ]);
}
```

This shows a page key (`partners`), a section key (`hero`), three inputs (`title`, `body`, `background`), a media/image input (`background`, type `ContentInputType::Image`), and translatable text inputs (`title`, `body`, both `'translatable' => true`).

## 6. How to add a new design section to an existing page

Example: add a `stats` section to the `home` page.

1. Open `WebsiteContentDefinitions.php`.
2. Find the `private static function home()` page definition.
3. Add a new `ContentSectionDefinition::make([...])` entry with stable key `stats` to the page's sections array.
4. Add content inputs matching the design.
5. Use a `ContentInputType::Repeater` item (see `self::itemsRepeater()` for the existing pattern) for repeated blocks if needed.
6. Use `ContentInputType::Image` (or another media type) for images/icons if needed.
7. Run `php artisan db:seed --class=PageSectionSeeder`.
8. Open the dashboard.
9. Confirm the new section appears under the Home page (via `WebsiteContentSectionsPage`).
10. Edit and save content.
11. Confirm the public API returns the new section.
12. Coordinate frontend rendering for `section_key = stats`.

Notes:

- Section keys are API contracts with the frontend.
- Do not rename section keys casually.
- Adding a new section is safer than renaming an existing section.

## 7. How to define content before running the seeder

Before running the seeder, developers can define:

- page key (`PageContentDefinition::make($key, ...)`)
- page labels (`label_en`, `label_ar` arguments)
- section keys (`section_key`)
- section labels (`label_en`, `label_ar`)
- content input fields (`items`, built from `ContentItemDefinition::make([...])`)
- default values, if the section provides `default_data`
- repeater item shape (`schema` on a `ContentInputType::Repeater` item)
- media field keys (the `key` of an item whose `type` is a media type)

After the seeder runs:

- pages are created from definitions (`PageSectionSeeder::resolvePage()`),
- sections are created from definitions (`PageSectionSeeder::seedSection()`),
- the dashboard form is generated from the input definitions (`PageSectionResource::buildComponents()` via `ContentInputFactory`),
- admins can edit the seeded/default content.

A page definition can also carry default page-level SEO: `PageContentDefinition::make($key, $labelEn, $labelAr, $sections, seo: ['meta_title' => ['en' => ..., 'ar' => ...], 'meta_description' => [...]])`. The seeder writes `meta_title` / `meta_description` (and `published_at` for published pages) **only into fields that are still empty**, so it also fills an already-seeded page without touching values edited under "SEO settings". `public_path` and `canonical_url` are never seeded, because they feed the sitemap and hreflang and must match the real frontend URLs.

If admin content already exists, the seeder does not overwrite it — `PageSectionSeeder::seedSection()` only writes `data` when the `PageSection` row is first created (`firstOrCreate`), and subsequent runs only refresh `label`/`sort_order`. Developers should not expect changing `default_data` in code to overwrite production/admin-edited content automatically.

## 8. Field/input types

The available input types live in `App\Support\Content\Enums\ContentInputType` and are mapped to Filament fields in `App\Support\Content\Forms\ContentInputFactory`. Only the types below exist — do not invent others.

| Type (enum case / value) | Purpose | Example usage | Stored value shape |
| --- | --- | --- | --- |
| `ShortText` / `short_text` | Single-line text | Titles, subtitles, CTA button text | string, or `{en: string, ar: string}` if `translatable` |
| `LongText` / `long_text` | Multi-line plain text | Body copy, meta description | string, or `{en, ar}` if translatable |
| `RichText` / `rich_text` | WYSIWYG/HTML editor | Long-form page body (e.g. privacy policy `body`) | HTML string, or `{en, ar}` if translatable |
| `Url` / `url` | URL input | Link fields | string URL |
| `Email` / `email` | Email input | Contact email field | string email |
| `Password` / `password` | Password input | Rare in content; masked text | string |
| `Phone` / `phone` | Phone number (rendered as short text) | Contact phone field | string |
| `Integer` / `integer` | Whole number | Counts, ordering values | integer |
| `Decimal` / `decimal` | Decimal number | Numeric stats | float |
| `Money` / `money` | Monetary amount (rendered as decimal) | Price display | float |
| `Percentage` / `percentage` | Percentage value (rendered as decimal) | Progress/stat values | float |
| `Boolean` / `boolean` | Toggle | Show/hide flags | boolean |
| `CheckboxList` / `checkbox_list` | Multiple choice via `settings['options']` | Tag selection | array of selected option keys |
| `Radio` / `radio` | Single choice via `settings['options']` | Layout variant | string option key |
| `Select` / `select` | Dropdown via `settings['options']` | Single choice field | string option key |
| `MultiSelect` / `multi_select` | Multi-select dropdown via `settings['options']` | Multiple choice field | array of option keys |
| `Image` / `image` | Single image upload (Spatie media) | `background`, hero image | media file, bound to a collection named after the item key |
| `MultiImage` / `multi_image` | Multiple image upload, reorderable | Gallery images | array of media files |
| `File` / `file` | Generic document upload | Attachments, downloadable PDFs | media file |
| `VideoUpload` / `video_upload` | Video file upload | Hero/background video | media file |
| `VideoUrl` / `video_url` | External video URL (rendered as URL input) | YouTube/Vimeo link | string URL |
| `AudioUpload` / `audio_upload` | Audio file upload | Audio clip | media file |
| `Date` / `date` | Date picker | Event date | date string |
| `DateTime` / `datetime` | Date and time picker | Scheduled content | datetime string |
| `Time` / `time` | Time picker | Opening hours | `H:i` string |
| `Color` / `color` | Color picker | Theme color field | string (hex/color value) |
| `Icon` / `icon` | Icon name input | Feature icon name | string icon identifier |
| `Cta` / `cta` | Composite call-to-action (label + URL, per locale) | `cta` item (see `self::cta()`) | array, e.g. `{en: {...}, ar: {...}}` |
| `Repeater` / `repeater` | Repeated group of nested items, defined via `schema` | Lists of cards/items | array of objects matching the `schema` |
| `KeyValue` / `key_value` | Free-form key/value pairs | Arbitrary metadata | associative array |
| `Hidden` / `hidden` | Hidden field | Non-editable stored value | any |

Validation rules for each type are derived in `ContentInputValidationMapper::rulesFor()` — every field is `nullable` plus a type-appropriate rule (e.g. `image`, `url`, `integer`); there is no `required` flag on `ContentItemDefinition`.

## 9. Repeater inputs

Repeaters (`ContentInputType::Repeater`) are used when a design section has repeated cards/items, for example:

- services/feature cards
- statistics
- testimonials
- buttons/links
- gallery items

Define a repeater using the current API (see `WebsiteContentDefinitions::itemsRepeater()` for the existing example):

```php
ContentItemDefinition::make([
    'key' => 'items',
    'type' => ContentInputType::Repeater,
    'label_en' => 'Benefits',
    'label_ar' => 'المزايا',
    'sort_order' => 2,
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
```

The `schema` array holds nested `ContentItemDefinition` instances describing the fields inside each repeated item. `ContentInputFactory::makeRepeater()` builds a Filament repeater field from this schema.

Expected JSON shape (stored under the repeater's key in `data`):

```json
{
  "items": [
    { "label": { "en": "Benefit one", "ar": "ميزة أولى" } },
    { "label": { "en": "Benefit two", "ar": "ميزة ثانية" } }
  ]
}
```

Repeater item keys (the `key` of each nested `ContentItemDefinition`) must remain stable once the frontend integrates with them, because the frontend reads each item by that key from the JSON array.

Optional `settings` on a repeater item (see `ContentInputFactory::makeRepeater()`):

| Setting | Effect |
| --- | --- |
| `max_items` | Refuses to save more rows than this (e.g. `6` feature cards). |
| `min_items` | Requires at least this many rows. |
| `item_label` | Key of a nested field whose current-locale value titles each collapsed row. |

Repeaters can nest (a `plans` row contains a `features` repeater), and nested fields may be translatable, colours, selects, money and so on.

## 10. Media inputs

- Media input keys map to Spatie Media Library collection names. `ContentInputFactory::makeMedia()` binds `SpatieMediaLibraryFileUpload::make($item->key)->collection($item->settings['collection'] ?? $item->key)`.
- Example: a `background` item key maps to the `background` media collection.
- Example: an `icon`-keyed image item maps to the `icon` media collection.
- Do not rename media item keys casually, because previously uploaded files remain attached to the collection name that was in use at upload time.
- The public API resource layer (`PageSectionResource` under `App\Http\Resources\Api\Public`) is responsible for including media URLs in the response if the section's media is exposed there.
- `PageSection` registers these collections: `background`, `image`, `image_small`, `logo`, `icon`, `gallery`, `video`. The API returns each under `media.<collection>` (a single-file collection is an object or `null`, `gallery` is an array). Register a new collection in `PageSection::registerMediaCollections()` *and* add it to the `media` block of the API `PageSectionResource` before pointing an item at it via `settings['collection']`.
- Media is stored per section row, so `image` on `hero` and `image` on `on_mobile` are separate uploads even though they share a collection name.
- `MultiImage` accepts `settings['max_files']` to cap the number of uploads, e.g. `['collection' => 'gallery', 'max_files' => 4]`.

### Images inside repeater rows

A repeater row has no media collection of its own, so an `Image` item in a repeater's `schema` is a plain upload (disk `filament_public`, folder `content/`) whose **file path is stored in the row's JSON**. Only `Image` can be nested; any other media type throws a `LogicException` when the form is built.

The public API converts those paths to absolute URLs (`Api\Public\PageSectionResource` → `App\Support\Content\Media\ContentMediaUrlResolver`, driven by the section definition), so the frontend always receives a URL or `null`:

```json
{ "features": [ { "title": "AI Study Assistant", "icon": "https://example.test/storage/content/robot.png" } ] }
```

## 11. Seeder behavior

Run the Website Content seeder directly:

```bash
php artisan db:seed --class=PageSectionSeeder
```

For a fresh local database:

```bash
php artisan migrate:fresh --seed
```

- On existing environments, avoid destructive commands like `migrate:fresh`.
- Prefer targeted seeding (`db:seed --class=PageSectionSeeder`) on environments with real data.
- Take a backup before any destructive database operation.
- The seeder creates missing pages/sections (`PageSectionSeeder::resolvePage()` / `seedSection()`).
- The seeder does not overwrite existing admin-edited content — only `label`/`sort_order` are refreshed on existing rows.
- Renaming a page key or section key causes the seeder to create a new `Page`/`PageSection` row under the new key, leaving the old row (and any content in it) behind.
- Prefer adding new keys over renaming existing keys.

## 12. Public API

```http
GET /api/public/pages/{slug}
GET /api/public/pages/{page_key}/sections
```

(Routes are defined in `routes/api/public.php`, registered under the `api/public` prefix — see `bootstrap/app.php`.)

- The frontend requests a page by key via `PageController::show()`.
- The frontend requests a page's sections by page key via `PageSectionController::index()`.
- The sections response includes `section_key` and `data` for each section (see `App\Http\Resources\Api\Public\PageSectionResource`).
- The frontend maps `section_key` to a frontend component.
- Content keys inside `data` must match the frontend contract for that section.

## 13. Admin dashboard workflow

1. Admin logs in.
2. Admin opens Website Content (`WebsiteContentPage`).
3. Admin selects a page card.
4. Admin sees generated sections (`WebsiteContentSectionsPage`).
5. Admin opens a section.
6. Admin sees generated form inputs (`PageSectionResource::form()`).
7. Admin edits content.
8. Admin saves.
9. The public API returns the updated content.

## 13a. Landing page contract (`home`)

The `home` page is the Z-MEDIX landing page (`design/Landing Page.png`). Fetch it with `GET /api/public/pages/home/sections?lang=en|ar`. `title` and `subtitle` come back at the top level of each section; everything else is under `data` (`description` included) and `media`.

| `section_key` | `data` / top level | `media` |
| --- | --- | --- |
| `hero` | `title`, `data.description` | `image`, `image_small` |
| `features` | `title`, `data.description`, `data.features[]` = `{title, description, icon}` (max 6) | — |
| `plans` | `title`, `data.description`, `data.plans[]` = `{title, price, discount, billing_type, features[{label}], background_color, icon, icon_color}` (max 6) | — |
| `ai_assistant` | `subtitle`, `title`, `data.description`, `data.highlights[]` = `{title, icon}` (max 3) | `image`, `logo`, `gallery` (max 4) |
| `on_mobile` | `subtitle`, `title`, `data.description`, `data.app_store_url`, `data.google_play_url` | `image` |

Notes for the frontend:

- `plans` holds every billing cycle: three rows with `billing_type: "monthly"` and three with `"yearly"`. Filter by `billing_type` when the Monthly/Yearly toggle changes.
- `price` is a number without a currency symbol. `discount` is a percentage (0–100) or `null`. `icon` is an icon key (e.g. `crown`) that the frontend maps to an icon; `background_color` / `icon_color` are hex colours.
- Feature and highlight `icon` values are image URLs (uploaded from the dashboard) or `null` until uploaded.
- Sections that are not defined for the page (the old `how_it_works`, `testimonials`, `cta_banner`, `faq` placeholders) are not returned, even if rows for them still exist in the database.

### Website information (Settings)

Site-wide information lives in Settings (dashboard: Site Settings), returned by `GET /api/public/settings?lang=en|ar` grouped by `group`:

| Group | Keys |
| --- | --- |
| `general` | `site_name`, `site_logo` (image URL or `null`), `app_store_url`, `google_play_url`, plus the existing contact keys |
| `footer` | `footer_description_1`, `footer_description_2`, `footer_copyright` (all translatable), plus the existing footer keys |
| `social` | `social_behance`, `social_instagram`, `social_linkedin`, `social_twitter`, `social_facebook`, `social_youtube` |

A **translatable setting** is a `Json` setting whose value is an `{"en": "...", "ar": "..."}` map. `SettingResource` returns the string for the request locale, and Site Settings shows one input per locale instead of raw JSON. A Json setting whose value is *not* a locale map stays a plain textarea.

The On Mobile section's store links and the Settings store links are independent values. Update both if the app links change.

`CmsSeeder` uses `updateOrCreate` on `key`, so re-running it resets every listed setting (including an uploaded logo and edited copy) to its default. Do not re-run it on an environment with real settings.

## 14. Adding a dashboard page — complete checklist

- [ ] Choose page key.
- [ ] Add page definition in `WebsiteContentDefinitions.php`.
- [ ] Add page labels (English and Arabic).
- [ ] Add sections.
- [ ] Add content inputs.
- [ ] Add `default_data` if needed.
- [ ] Add the page to `self::all()`.
- [ ] Add a dashboard page card in `WebsiteContentPage::getCards()` and an entry in `WebsiteContentSectionsPage::PAGE_TITLES`.
- [ ] Add the page key to `PageSectionSeeder::DESIGNED_PAGE_KEYS`.
- [ ] Run `PageSectionSeeder`.
- [ ] Open admin dashboard.
- [ ] Confirm page card appears.
- [ ] Confirm sections appear.
- [ ] Confirm generated form inputs appear.
- [ ] Save content.
- [ ] Test public API.
- [ ] Add/update tests.
- [ ] Coordinate frontend rendering.

## 15. Adding a design section — complete checklist

- [ ] Choose section key.
- [ ] Add section definition.
- [ ] Add inputs matching the design.
- [ ] Keep keys stable.
- [ ] Use a repeater for repeated blocks.
- [ ] Use media inputs for images/files.
- [ ] Run `PageSectionSeeder`.
- [ ] Confirm section appears in dashboard.
- [ ] Confirm generated form is correct.
- [ ] Save content.
- [ ] Test public API.
- [ ] Coordinate frontend component for this `section_key`.

## 16. Safe change rules

**Safe:**

- adding a new page key
- adding a new section key
- adding optional content inputs
- adding new repeater fields
- adding neutral placeholder content

**Needs caution:**

- renaming page keys
- renaming section keys
- renaming content item keys
- renaming media item keys
- changing repeater item shape
- deleting sections used by the frontend
- deleting media fields with existing uploads

## 17. Testing checklist

```bash
php artisan migrate:fresh --seed
vendor/bin/phpunit -d memory_limit=512M --filter=WebsiteContent
vendor/bin/phpunit -d memory_limit=512M --filter=PageSection
vendor/bin/phpunit -d memory_limit=512M --filter=Cms
vendor/bin/pint --dirty
```

Manual checks:

- page card appears
- sections appear
- generated inputs match definitions
- saving works
- public API returns updated data

## 18. Final note

This guide belongs to the neutral starter/core repository. It must stay product-neutral. Product-specific page copy, branding, and frontend implementation belong to the consuming application.
