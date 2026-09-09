<?php

namespace App\Filament\Resources;

use App\Enums\ContentStatus;
use App\Filament\Resources\FaqResource\Pages\CreateFaq;
use App\Filament\Resources\FaqResource\Pages\EditFaq;
use App\Filament\Resources\FaqResource\Pages\ListFaqs;
use App\Models\Faq;
use App\Models\FaqCategory;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class FaqResource extends Resource
{
    protected static ?string $model = Faq::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static ?int $navigationSort = 5;

    public static function getNavigationLabel(): string
    {
        return __('admin.cms.faqs_navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('admin.cms.faqs_model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.cms.faqs_plural_model_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.cms.navigation_group_content');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        Select::make('faq_category_id')
                            ->label(__('admin.cms.field_category'))
                            ->options(FaqCategory::query()->whereNotNull('name')->pluck('name', 'id')->map(fn ($name) => is_array($name) ? ($name['en'] ?? $name['ar'] ?? '') : $name))
                            ->nullable()
                            ->searchable()
                            ->columnSpan(2),
                        Select::make('status')
                            ->label(__('admin.cms.field_status'))
                            ->options([
                                ContentStatus::Draft->value => __('admin.cms.status_draft'),
                                ContentStatus::Published->value => __('admin.cms.status_published'),
                                ContentStatus::Archived->value => __('admin.cms.status_archived'),
                            ])
                            ->required()
                            ->default(ContentStatus::Published->value),
                        TextInput::make('sort_order')
                            ->label(__('admin.cms.field_sort_order'))
                            ->integer()
                            ->default(0),
                    ])
                    ->columns(4),

                Tabs::make('Locales')
                    ->tabs([
                        Tab::make(__('admin.cms.tab_english'))
                            ->schema([
                                TextInput::make('question.en')
                                    ->label(__('admin.cms.field_question_en'))
                                    ->required()
                                    ->maxLength(500),
                                Textarea::make('answer.en')
                                    ->label(__('admin.cms.field_answer_en'))
                                    ->rows(4),
                            ]),
                        Tab::make(__('admin.cms.tab_arabic'))
                            ->schema([
                                TextInput::make('question.ar')
                                    ->label(__('admin.cms.field_question_ar'))
                                    ->maxLength(500),
                                Textarea::make('answer.ar')
                                    ->label(__('admin.cms.field_answer_ar'))
                                    ->rows(4),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('question')
                    ->label(__('admin.cms.field_question_en'))
                    ->getStateUsing(fn (Faq $record): string => $record->getTranslation('question', 'en') ?: $record->getTranslation('question', 'ar'))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('question->en', 'like', "%{$search}%")->orWhere('question->ar', 'like', "%{$search}%"))
                    ->limit(80),
                TextColumn::make('category.name')
                    ->label(__('admin.cms.field_category'))
                    ->getStateUsing(fn (Faq $record): string => $record->category?->getTranslation('name', 'en') ?? '-')
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->label(__('admin.cms.field_sort_order'))
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('admin.cms.field_status'))
                    ->badge()
                    ->color(fn (ContentStatus $state): string => match ($state) {
                        ContentStatus::Published => 'success',
                        ContentStatus::Draft => 'warning',
                        ContentStatus::Archived => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('faq_category_id')
                    ->label(__('admin.cms.field_category'))
                    ->relationship('category', 'name'),
                SelectFilter::make('status')
                    ->label(__('admin.cms.field_status'))
                    ->options([
                        ContentStatus::Draft->value => __('admin.cms.status_draft'),
                        ContentStatus::Published->value => __('admin.cms.status_published'),
                        ContentStatus::Archived->value => __('admin.cms.status_archived'),
                    ]),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->defaultSort('sort_order');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFaqs::route('/'),
            'create' => CreateFaq::route('/create'),
            'edit' => EditFaq::route('/{record}/edit'),
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
