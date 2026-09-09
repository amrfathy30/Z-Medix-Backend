<?php

namespace App\Support\Content\Validation;

use App\Support\Content\Enums\ContentInputType;

/**
 * Derives Laravel validation rules from a ContentInputType alone. Website
 * Content item definitions never declare `required` — every field is
 * optional by default, and the rules below only add the type-appropriate
 * constraint on top of `nullable`.
 */
class ContentInputValidationMapper
{
    /** @return list<string> */
    public function rulesFor(ContentInputType $type): array
    {
        return match ($type) {
            ContentInputType::ShortText => ['nullable', 'string', 'max:255'],
            ContentInputType::LongText => ['nullable', 'string'],
            // Filament RichEditor validates its raw TipTap document state
            // before dehydrating it to the HTML string persisted in JSON.
            ContentInputType::RichText => ['nullable', 'array'],
            ContentInputType::Url, ContentInputType::VideoUrl => ['nullable', 'url'],
            ContentInputType::Email => ['nullable', 'email'],
            ContentInputType::Password => ['nullable', 'string'],
            ContentInputType::Phone => ['nullable', 'string', 'max:32'],
            ContentInputType::Integer => ['nullable', 'integer'],
            ContentInputType::Decimal, ContentInputType::Money, ContentInputType::Percentage => ['nullable', 'numeric'],
            ContentInputType::Boolean => ['nullable', 'boolean'],
            ContentInputType::CheckboxList, ContentInputType::MultiSelect => ['nullable', 'array'],
            ContentInputType::Radio, ContentInputType::Select => ['nullable', 'string'],
            ContentInputType::Image => [
                'nullable',
                'image',
                'mimetypes:'.implode(',', config('media.image_mime_types', [])),
                'max:'.config('media.max_image_size_kb', 5 * 1024),
            ],
            ContentInputType::MultiImage => ['nullable', 'array'],
            ContentInputType::File => [
                'nullable',
                'file',
                'mimetypes:'.implode(',', config('media.document_mime_types', [])),
                'max:'.config('media.max_document_size_kb', 10 * 1024),
            ],
            ContentInputType::VideoUpload => [
                'nullable',
                'file',
                'mimetypes:'.implode(',', config('media.video_mime_types', [])),
                'max:'.config('media.max_video_size_kb', 50 * 1024),
            ],
            ContentInputType::AudioUpload => [
                'nullable',
                'file',
                'mimetypes:'.implode(',', config('media.audio_mime_types', [])),
                'max:'.config('media.max_audio_size_kb', 20 * 1024),
            ],
            ContentInputType::Date => ['nullable', 'date'],
            ContentInputType::DateTime => ['nullable', 'date'],
            ContentInputType::Time => ['nullable', 'date_format:H:i'],
            ContentInputType::Color => ['nullable', 'string', 'max:32'],
            ContentInputType::Icon => ['nullable', 'string', 'max:255'],
            ContentInputType::Cta => ['nullable', 'array'],
            ContentInputType::Repeater => ['nullable', 'array'],
            ContentInputType::KeyValue => ['nullable', 'array'],
            ContentInputType::Hidden => ['nullable'],
        };
    }
}
