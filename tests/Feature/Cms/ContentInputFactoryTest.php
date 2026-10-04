<?php

namespace Tests\Feature\Cms;

use App\Support\Content\Definitions\ContentDefinitionRegistry;
use App\Support\Content\Definitions\ContentItemDefinition;
use App\Support\Content\Definitions\Exceptions\MissingContentDefinitionException;
use App\Support\Content\Enums\ContentInputType;
use App\Support\Content\Forms\ContentInputFactory;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use LogicException;
use ReflectionMethod;
use Tests\TestCase;

class ContentInputFactoryTest extends TestCase
{
    private ContentInputFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = new ContentInputFactory;
    }

    public function test_short_text_renders_text_input_with_data_path(): void
    {
        $item = ContentItemDefinition::make([
            'key' => 'title',
            'type' => ContentInputType::ShortText,
            'label_en' => 'Title',
            'label_ar' => 'العنوان',
        ]);

        $component = $this->factory->make($item, 'data');

        $this->assertInstanceOf(TextInput::class, $component);
        $this->assertSame('data.title', $component->getName());
    }

    public function test_translatable_short_text_renders_one_input_per_locale(): void
    {
        $item = ContentItemDefinition::make([
            'key' => 'title',
            'type' => ContentInputType::ShortText,
            'label_en' => 'Title',
            'label_ar' => 'العنوان',
            'translatable' => true,
        ]);

        $component = $this->factory->make($item, 'data');

        $this->assertInstanceOf(Group::class, $component);

        $names = array_map(
            fn (TextInput $field): string => $field->getName(),
            $component->getDefaultChildComponents(),
        );

        $this->assertContains('data.title.en', $names);
        $this->assertContains('data.title.ar', $names);
    }

    public function test_long_text_renders_textarea(): void
    {
        $item = ContentItemDefinition::make([
            'key' => 'description',
            'type' => ContentInputType::LongText,
            'label_en' => 'Description',
            'label_ar' => 'الوصف',
        ]);

        $component = $this->factory->make($item, 'data');

        $this->assertInstanceOf(Textarea::class, $component);
    }

    public function test_rich_text_renders_rich_editor(): void
    {
        $item = ContentItemDefinition::make([
            'key' => 'body',
            'type' => ContentInputType::RichText,
            'label_en' => 'Body',
            'label_ar' => 'النص',
        ]);

        $component = $this->factory->make($item, 'data');

        $this->assertInstanceOf(RichEditor::class, $component);
    }

    public function test_image_renders_file_upload_configured_for_images(): void
    {
        $item = ContentItemDefinition::make([
            'key' => 'background_image',
            'type' => ContentInputType::Image,
            'label_en' => 'Background Image',
            'label_ar' => 'صورة الخلفية',
        ]);

        $component = $this->factory->make($item, 'data');

        $this->assertInstanceOf(FileUpload::class, $component);
        // Media items ignore basePath and bind directly to a media collection named after the item key.
        $this->assertSame('background_image', $component->getName());
    }

    public function test_video_upload_renders_file_upload(): void
    {
        $item = ContentItemDefinition::make([
            'key' => 'promo_video',
            'type' => ContentInputType::VideoUpload,
            'label_en' => 'Promo Video',
            'label_ar' => 'فيديو ترويجي',
        ]);

        $component = $this->factory->make($item, 'data');

        $this->assertInstanceOf(FileUpload::class, $component);
    }

    public function test_cta_renders_group_with_text_and_url_fields(): void
    {
        $item = ContentItemDefinition::make([
            'key' => 'cta',
            'type' => ContentInputType::Cta,
            'label_en' => 'Call to Action',
            'label_ar' => 'دعوة لاتخاذ إجراء',
        ]);

        $component = $this->factory->make($item, 'data');

        $this->assertInstanceOf(Group::class, $component);

        $names = array_map(
            fn ($field): string => $field->getName(),
            $component->getDefaultChildComponents(),
        );

        $this->assertContains('data.cta.text.en', $names);
        $this->assertContains('data.cta.text.ar', $names);
        $this->assertContains('data.cta.url', $names);
    }

    public function test_repeater_renders_with_nested_item_schema(): void
    {
        $item = ContentItemDefinition::make([
            'key' => 'items',
            'type' => ContentInputType::Repeater,
            'label_en' => 'Items',
            'label_ar' => 'العناصر',
            'schema' => [
                ContentItemDefinition::make([
                    'key' => 'label',
                    'type' => ContentInputType::ShortText,
                    'label_en' => 'Label',
                    'label_ar' => 'التسمية',
                ]),
            ],
        ]);

        $component = $this->factory->make($item, 'data');

        $this->assertInstanceOf(Repeater::class, $component);
        $this->assertSame('data.items', $component->getName());

        $nestedNames = array_map(
            fn ($field): string => $field->getName(),
            $component->getDefaultChildComponents(),
        );

        $this->assertContains('label', $nestedNames);
    }

    private function imageItem(string $key, array $settings = []): ContentItemDefinition
    {
        return ContentItemDefinition::make([
            'key' => $key,
            'type' => ContentInputType::Image,
            'label_en' => 'Icon',
            'label_ar' => 'الأيقونة',
            'settings' => $settings,
        ]);
    }

    public function test_image_inside_a_repeater_is_a_plain_upload_not_bound_to_a_media_collection(): void
    {
        $item = ContentItemDefinition::make([
            'key' => 'features',
            'type' => ContentInputType::Repeater,
            'label_en' => 'Features',
            'label_ar' => 'الميزات',
            'schema' => [$this->imageItem('icon')],
        ]);

        $component = $this->factory->make($item, 'data');
        [$icon] = $component->getDefaultChildComponents();

        $this->assertInstanceOf(FileUpload::class, $icon);
        $this->assertNotInstanceOf(SpatieMediaLibraryFileUpload::class, $icon);
        $this->assertSame('icon', $icon->getName());
        $this->assertSame('filament_public', $icon->getDiskName());
    }

    public function test_only_images_can_be_nested_in_a_repeater(): void
    {
        $item = ContentItemDefinition::make([
            'key' => 'rows',
            'type' => ContentInputType::Repeater,
            'label_en' => 'Rows',
            'label_ar' => 'صفوف',
            'schema' => [ContentItemDefinition::make([
                'key' => 'attachment',
                'type' => ContentInputType::File,
                'label_en' => 'Attachment',
                'label_ar' => 'مرفق',
            ])],
        ]);

        $this->expectException(LogicException::class);

        $this->factory->make($item, 'data');
    }

    public function test_repeater_applies_min_and_max_items_settings(): void
    {
        $item = ContentItemDefinition::make([
            'key' => 'plans',
            'type' => ContentInputType::Repeater,
            'label_en' => 'Plans',
            'label_ar' => 'الباقات',
            'settings' => ['min_items' => 1, 'max_items' => 6],
            'schema' => [ContentItemDefinition::make([
                'key' => 'label',
                'type' => ContentInputType::ShortText,
                'label_en' => 'Label',
                'label_ar' => 'التسمية',
            ])],
        ]);

        $component = $this->factory->make($item, 'data');

        $this->assertSame(1, $component->getMinItems());
        $this->assertSame(6, $component->getMaxItems());
    }

    public function test_repeater_without_limits_stays_unbounded(): void
    {
        $item = ContentItemDefinition::make([
            'key' => 'items',
            'type' => ContentInputType::Repeater,
            'label_en' => 'Items',
            'label_ar' => 'العناصر',
        ]);

        $component = $this->factory->make($item, 'data');

        $this->assertNull($component->getMaxItems());
    }

    public function test_multi_image_applies_max_files_setting_and_collection_override(): void
    {
        $item = ContentItemDefinition::make([
            'key' => 'supporting_images',
            'type' => ContentInputType::MultiImage,
            'label_en' => 'Supporting Images',
            'label_ar' => 'الصور الداعمة',
            'settings' => ['collection' => 'gallery', 'max_files' => 4],
        ]);

        $component = $this->factory->make($item, 'data');

        $this->assertInstanceOf(SpatieMediaLibraryFileUpload::class, $component);
        $this->assertSame('supporting_images', $component->getName());
        $this->assertSame('gallery', $component->getCollection());
        $this->assertSame(4, $component->getMaxFiles());
    }

    public function test_repeater_row_label_uses_the_current_locale_then_english_then_plain_strings(): void
    {
        $rowLabel = new ReflectionMethod(ContentInputFactory::class, 'rowLabel');

        app()->setLocale('ar');
        $this->assertSame('عنوان', $rowLabel->invoke($this->factory, ['en' => 'Title', 'ar' => 'عنوان']));
        $this->assertSame('Title', $rowLabel->invoke($this->factory, ['en' => 'Title']));

        $this->assertSame('Plain', $rowLabel->invoke($this->factory, 'Plain'));
        $this->assertNull($rowLabel->invoke($this->factory, ['en' => '']));
        $this->assertNull($rowLabel->invoke($this->factory, null));
    }

    public function test_missing_definition_for_unknown_page_throws_via_registry(): void
    {
        $registry = app(ContentDefinitionRegistry::class);

        $this->expectException(MissingContentDefinitionException::class);

        $registry->forSection('unknown-page', 'unknown-section');
    }
}
