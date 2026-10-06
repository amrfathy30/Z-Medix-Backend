<?php

namespace App\Filament\Resources;

use App\Enums\ContentStatus;
use App\Filament\Resources\ChapterResource\Pages\EditChapter;
use App\Filament\Resources\ChapterResource\Pages\ViewChapter;
use App\Filament\Resources\ChapterResource\RelationManagers\PagesRelationManager;
use App\Models\Chapter;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Chapters are reached through their subject, never from the sidebar, so this
 * resource registers no navigation entry and no index page.
 */
class ChapterResource extends Resource
{
    protected static ?string $model = Chapter::class;

    protected static ?string $recordTitleAttribute = 'title';

    public static function shouldRegisterNavigation(array $parameters = []): bool
    {
        return false;
    }

    public static function getModelLabel(): string
    {
        return __('admin.learning.chapters_model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.learning.chapters_plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.learning.chapter_section_details'))
                    ->schema([
                        self::titleField()->columnSpanFull(),
                        self::statusField(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function titleField(): TextInput
    {
        return TextInput::make('title')
            ->label(__('admin.learning.field_chapter_title'))
            ->required()
            ->maxLength(255);
    }

    /**
     * A chapter is only usable for student study once its quiz holds a question,
     * so publishing is refused until then. On create there is no quiz yet, hence
     * no question either, and the same rule applies.
     */
    public static function statusField(): Select
    {
        return Select::make('status')
            ->label(__('admin.learning.field_status'))
            ->options(SubjectResource::statusOptions())
            ->required()
            ->default(ContentStatus::Draft->value)
            ->rule(static function (?Model $record): Closure {
                return static function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                    if ($value !== ContentStatus::Published->value) {
                        return;
                    }

                    if (! ($record instanceof Chapter) || ! $record->isPublishable()) {
                        $fail(__('admin.learning.chapter_publish_requires_questions'));
                    }
                };
            });
    }

    public static function getRelations(): array
    {
        return [
            'pages' => PagesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'view' => ViewChapter::route('/{record}'),
            'edit' => EditChapter::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
