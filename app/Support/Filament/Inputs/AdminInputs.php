<?php

namespace App\Support\Filament\Inputs;

use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\MultiSelect;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;

/**
 * Generic, manually-callable Filament form building blocks. Not tied to
 * Website Content or any product-specific resource — normal Filament
 * resources may call these directly later, but no existing resource has
 * been refactored to do so yet.
 */
class AdminInputs
{
    public static function shortText(string $name): TextInput
    {
        return TextInput::make($name)->maxLength(255);
    }

    public static function longText(string $name): Textarea
    {
        return Textarea::make($name)->rows(4);
    }

    public static function richText(string $name): RichEditor
    {
        return RichEditor::make($name);
    }

    public static function url(string $name): TextInput
    {
        return TextInput::make($name)->url()->maxLength(2048);
    }

    public static function email(string $name): TextInput
    {
        return TextInput::make($name)->email()->maxLength(255);
    }

    public static function password(string $name): TextInput
    {
        return TextInput::make($name)->password()->maxLength(255);
    }

    public static function integer(string $name): TextInput
    {
        return TextInput::make($name)->numeric()->integer();
    }

    public static function decimal(string $name): TextInput
    {
        return TextInput::make($name)->numeric()->step('0.01');
    }

    public static function money(string $name): TextInput
    {
        return TextInput::make($name)->numeric()->step('0.01')->prefix('$');
    }

    public static function percentage(string $name): TextInput
    {
        return TextInput::make($name)->numeric()->step('0.01')->suffix('%');
    }

    public static function boolean(string $name): Toggle
    {
        return Toggle::make($name);
    }

    public static function checkbox(string $name): Checkbox
    {
        return Checkbox::make($name);
    }

    /** @param  array<string, string>  $options */
    public static function checkboxList(string $name, array $options): CheckboxList
    {
        return CheckboxList::make($name)->options($options);
    }

    /** @param  array<string, string>  $options */
    public static function radio(string $name, array $options): Radio
    {
        return Radio::make($name)->options($options);
    }

    /** @param  array<string, string>  $options */
    public static function select(string $name, array $options): Select
    {
        return Select::make($name)->options($options)->searchable();
    }

    /** @param  array<string, string>  $options */
    public static function multiSelect(string $name, array $options): MultiSelect
    {
        return MultiSelect::make($name)->options($options)->searchable();
    }

    public static function image(string $name): FileUpload
    {
        return FileUpload::make($name)
            ->image()
            ->disk('filament_public')
            ->visibility('public')
            ->acceptedFileTypes(config('media.image_mime_types', []))
            ->maxSize(config('media.max_image_size_kb', 5 * 1024));
    }

    public static function multiImage(string $name): FileUpload
    {
        return self::image($name)->multiple()->reorderable();
    }

    public static function file(string $name): FileUpload
    {
        return FileUpload::make($name)
            ->disk('filament_public')
            ->visibility('public')
            ->acceptedFileTypes(config('media.document_mime_types', []))
            ->maxSize(config('media.max_document_size_kb', 10 * 1024));
    }

    public static function videoUpload(string $name): FileUpload
    {
        return FileUpload::make($name)
            ->disk('filament_public')
            ->visibility('public')
            ->acceptedFileTypes(config('media.video_mime_types', []))
            ->maxSize(config('media.max_video_size_kb', 50 * 1024));
    }

    public static function videoUrl(string $name): TextInput
    {
        return TextInput::make($name)->url()->maxLength(2048);
    }

    public static function audioUpload(string $name): FileUpload
    {
        return FileUpload::make($name)
            ->disk('filament_public')
            ->visibility('public')
            ->acceptedFileTypes(config('media.audio_mime_types', []))
            ->maxSize(config('media.max_audio_size_kb', 20 * 1024));
    }

    public static function date(string $name): DatePicker
    {
        return DatePicker::make($name);
    }

    public static function dateTime(string $name): DateTimePicker
    {
        return DateTimePicker::make($name);
    }

    public static function time(string $name): TimePicker
    {
        return TimePicker::make($name);
    }

    public static function color(string $name): ColorPicker
    {
        return ColorPicker::make($name);
    }

    public static function icon(string $name): TextInput
    {
        return TextInput::make($name)->maxLength(255);
    }

    public static function hidden(string $name): Hidden
    {
        return Hidden::make($name);
    }

    public static function keyValue(string $name): KeyValue
    {
        return KeyValue::make($name);
    }

    /**
     * Composite CTA input: bilingual button text plus a single URL field.
     *
     * @param  list<string>  $locales
     */
    public static function cta(string $name, array $locales = ['en', 'ar']): Group
    {
        $textFields = array_map(
            fn (string $locale): TextInput => TextInput::make("{$name}.text.{$locale}")->maxLength(255),
            $locales,
        );

        return Group::make([
            ...$textFields,
            self::url("{$name}.url"),
        ])->columns(2);
    }

    /**
     * @param  list<Component>  $schema
     */
    public static function repeater(string $name, array $schema): Repeater
    {
        return Repeater::make($name)
            ->schema($schema)
            ->reorderable()
            ->collapsible();
    }

    /**
     * Composite phone input: country code + number + type + default flag.
     *
     * @param  array<string, string>  $typeOptions
     */
    public static function phoneRepeater(string $name, array $typeOptions = []): Repeater
    {
        return self::repeater($name, [
            TextInput::make('country_code')->maxLength(8),
            TextInput::make('number')->tel()->maxLength(32),
            Select::make('type')->options($typeOptions)->searchable(),
            Toggle::make('is_default'),
        ])->columns(2);
    }
}
