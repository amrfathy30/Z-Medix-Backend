<?php

namespace App\Support\Content\Forms;

use App\Support\Content\Definitions\ContentItemDefinition;
use App\Support\Content\Enums\ContentInputType;
use App\Support\Content\Validation\ContentInputValidationMapper;
use App\Support\Filament\Inputs\AdminInputs;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;

/**
 * Converts a ContentItemDefinition into a Filament component, delegating
 * the actual field construction to AdminInputs and attaching validation
 * rules from ContentInputValidationMapper. This is the only class that
 * should know how a Website Content item definition maps to a form field —
 * Website Content resources should call this instead of building Filament
 * components by hand.
 */
class ContentInputFactory
{
    /** @var list<string> */
    private const DEFAULT_LOCALES = ['en', 'ar'];

    /** @var list<ContentInputType> */
    private const MEDIA_TYPES = [
        ContentInputType::Image,
        ContentInputType::MultiImage,
        ContentInputType::File,
        ContentInputType::VideoUpload,
        ContentInputType::AudioUpload,
    ];

    public function __construct(
        private readonly ContentInputValidationMapper $validationMapper = new ContentInputValidationMapper,
    ) {}

    /**
     * @param  string  $basePath  State path the item's key is nested under, e.g. "data". Ignored for
     *                            media types, which bind directly to a Spatie media collection instead.
     */
    public function make(ContentItemDefinition $item, string $basePath = 'data'): Component
    {
        if (in_array($item->type, self::MEDIA_TYPES, true)) {
            return $this->applyMeta($item, $this->makeMedia($item));
        }

        $path = $this->path($basePath, $item->key);

        if ($item->type === ContentInputType::Repeater) {
            return $this->makeRepeater($item, $path);
        }

        if ($item->type === ContentInputType::Cta) {
            return $this->makeCta($item, $path);
        }

        if ($item->translatable) {
            return $this->makeTranslatable($item, $path);
        }

        return $this->applyMeta($item, $this->leaf($item, $path));
    }

    private function leaf(ContentItemDefinition $item, string $path): Field
    {
        $field = match ($item->type) {
            ContentInputType::ShortText => AdminInputs::shortText($path),
            ContentInputType::LongText => AdminInputs::longText($path),
            ContentInputType::RichText => AdminInputs::richText($path),
            ContentInputType::Url, ContentInputType::VideoUrl => AdminInputs::url($path),
            ContentInputType::Email => AdminInputs::email($path),
            ContentInputType::Password => AdminInputs::password($path),
            ContentInputType::Phone => AdminInputs::shortText($path),
            ContentInputType::Integer => AdminInputs::integer($path),
            ContentInputType::Decimal => AdminInputs::decimal($path),
            ContentInputType::Money => AdminInputs::money($path),
            ContentInputType::Percentage => AdminInputs::percentage($path),
            ContentInputType::Boolean => AdminInputs::boolean($path),
            ContentInputType::CheckboxList => AdminInputs::checkboxList($path, $item->settings['options'] ?? []),
            ContentInputType::Radio => AdminInputs::radio($path, $item->settings['options'] ?? []),
            ContentInputType::Select => AdminInputs::select($path, $item->settings['options'] ?? []),
            ContentInputType::MultiSelect => AdminInputs::multiSelect($path, $item->settings['options'] ?? []),
            ContentInputType::Date => AdminInputs::date($path),
            ContentInputType::DateTime => AdminInputs::dateTime($path),
            ContentInputType::Time => AdminInputs::time($path),
            ContentInputType::Color => AdminInputs::color($path),
            ContentInputType::Icon => AdminInputs::icon($path),
            ContentInputType::KeyValue => AdminInputs::keyValue($path),
            ContentInputType::Hidden => AdminInputs::hidden($path),
            ContentInputType::Repeater, ContentInputType::Cta,
            ContentInputType::Image, ContentInputType::MultiImage,
            ContentInputType::File, ContentInputType::VideoUpload,
            ContentInputType::AudioUpload => throw new \LogicException(
                'Repeater, Cta, and media types are handled before reaching leaf().'
            ),
        };

        return $field->rules($this->validationMapper->rulesFor($item->type));
    }

    /**
     * Media items bind directly to a Spatie media collection named after
     * the item key (or `settings['collection']` if overridden), matching
     * how PageSection::registerMediaCollections() already exposes
     * background/image/icon/gallery/video. Previews, replace, and remove
     * are provided natively by SpatieMediaLibraryFileUpload.
     */
    private function makeMedia(ContentItemDefinition $item): SpatieMediaLibraryFileUpload
    {
        $collection = $item->settings['collection'] ?? $item->key;

        $upload = SpatieMediaLibraryFileUpload::make($item->key)->collection($collection);

        return match ($item->type) {
            ContentInputType::Image => $upload->image()
                ->acceptedFileTypes(config('media.image_mime_types', []))
                ->maxSize(config('media.max_image_size_kb', 5 * 1024))
                ->columnSpanFull(),
            ContentInputType::MultiImage => $upload->image()->multiple()->reorderable()
                ->panelLayout('grid')
                ->acceptedFileTypes(config('media.image_mime_types', []))
                ->maxSize(config('media.max_image_size_kb', 5 * 1024))
                ->helperText(__('admin.cms.gallery_helper'))
                ->columnSpanFull(),
            ContentInputType::File => $upload
                ->acceptedFileTypes(config('media.document_mime_types', []))
                ->maxSize(config('media.max_document_size_kb', 10 * 1024))
                ->columnSpanFull(),
            ContentInputType::VideoUpload => $upload
                ->acceptedFileTypes(config('media.video_mime_types', []))
                ->maxSize(config('media.max_video_size_kb', 50 * 1024))
                ->columnSpanFull(),
            ContentInputType::AudioUpload => $upload
                ->acceptedFileTypes(config('media.audio_mime_types', []))
                ->maxSize(config('media.max_audio_size_kb', 20 * 1024))
                ->columnSpanFull(),
            default => $upload->columnSpanFull(),
        };
    }

    private function makeTranslatable(ContentItemDefinition $item, string $path): Component
    {
        $locales = $item->settings['locales'] ?? self::DEFAULT_LOCALES;

        $fields = array_map(
            fn (string $locale): Field => $this->applyMeta($item, $this->leaf($item, "{$path}.{$locale}"), $locale),
            $locales,
        );

        return Group::make($fields)->columns(min(count($fields), 2));
    }

    private function makeCta(ContentItemDefinition $item, string $path): Component
    {
        $locales = $item->settings['locales'] ?? self::DEFAULT_LOCALES;

        return AdminInputs::cta($path, $locales)
            ->extraAttributes(['data-content-key' => $item->key]);
    }

    private function makeRepeater(ContentItemDefinition $item, string $path): Component
    {
        $schema = array_map(
            fn (ContentItemDefinition $nested): Component => $this->make($nested, ''),
            $item->schema,
        );

        return AdminInputs::repeater($path, $schema)->label($item->labelEn);
    }

    private function applyMeta(ContentItemDefinition $item, Field $field, ?string $locale = null): Field
    {
        $label = $locale === 'ar' ? $item->labelAr : $item->labelEn;
        $help = $locale === 'ar' ? $item->helpAr : $item->helpEn;

        $field = $field->label($label);

        if ($help !== null) {
            $field = $field->helperText($help);
        }

        if (is_int($item->columnSpan) || is_string($item->columnSpan)) {
            $field = $field->columnSpan($item->columnSpan);
        }

        return $field;
    }

    private function path(string $basePath, string $key): string
    {
        return $basePath === '' ? $key : "{$basePath}.{$key}";
    }
}
