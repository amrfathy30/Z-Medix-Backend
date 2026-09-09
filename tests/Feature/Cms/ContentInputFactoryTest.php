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
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
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

    public function test_missing_definition_for_unknown_page_throws_via_registry(): void
    {
        $registry = app(ContentDefinitionRegistry::class);

        $this->expectException(MissingContentDefinitionException::class);

        $registry->forSection('unknown-page', 'unknown-section');
    }
}
