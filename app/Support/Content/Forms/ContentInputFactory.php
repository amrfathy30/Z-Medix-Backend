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
     * @param  bool  $nested  True when the item is a field of a repeater row. A row has no media
     *                        collection of its own, so a nested Image stores its file path in the row
     *                        instead (see ContentMediaUrlResolver for the public URL).
     */
    public function make(ContentItemDefinition $item, string $basePath = 'data', bool $nested = false): Component
    {
        if ($nested && in_array($item->type, self::MEDIA_TYPES, true)) {
            return $this->applyMeta($item, $this->makeNestedImage($item));
        }

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
            ContentInputType::MultiImage => $this->limitFiles(
                $upload->image()->multiple()->reorderable()
                    ->panelLayout('grid')
                    ->acceptedFileTypes(config('media.image_mime_types', []))
                    ->maxSize(config('media.max_image_size_kb', 5 * 1024))
                    ->helperText(__('admin.cms.gallery_helper'))
                    ->columnSpanFull(),
                $item,
            ),
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

    /** `settings['max_files']` caps how many files a MultiImage accepts. */
    private function limitFiles(SpatieMediaLibraryFileUpload $upload, ContentItemDefinition $item): SpatieMediaLibraryFileUpload
    {
        if (isset($item->settings['max_files'])) {
            $upload->maxFiles((int) $item->settings['max_files']);
        }

        return $upload;
    }

    /**
     * Image field of a repeater row. Unlike makeMedia() it is a plain
     * FileUpload: the stored file path lives inside the row's JSON, so it is
     * not bound to a media collection and skips the mapper's `image`/`max`
     * rules (they would reject an already-stored path string on save).
     */
    private function makeNestedImage(ContentItemDefinition $item): Field
    {
        if ($item->type !== ContentInputType::Image) {
            throw new \LogicException("Only Image items can be nested in a repeater; '{$item->key}' is {$item->type->value}.");
        }

        return AdminInputs::image($item->key)
            ->directory($item->settings['directory'] ?? 'content')
            ->imagePreviewHeight('80');
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
            fn (ContentItemDefinition $nested): Component => $this->make($nested, '', nested: true),
            $item->schema,
        );

        $repeater = AdminInputs::repeater($path, $schema)->label($item->labelEn);

        if (isset($item->settings['min_items'])) {
            $repeater->minItems((int) $item->settings['min_items']);
        }

        if (isset($item->settings['max_items'])) {
            $repeater->maxItems((int) $item->settings['max_items']);
        }

        // Collapsed rows otherwise all read "Item 1", "Item 2", ...
        if (isset($item->settings['item_label'])) {
            $labelKey = $item->settings['item_label'];

            $repeater->itemLabel(fn (array $state): ?string => $this->rowLabel($state[$labelKey] ?? null));
        }

        return $repeater;
    }

    /** Current-locale text of a row's label field, whether it is translatable or a plain string. */
    private function rowLabel(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value[app()->getLocale()] ?? $value['en'] ?? null;
        }

        return is_string($value) && $value !== '' ? $value : null;
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
