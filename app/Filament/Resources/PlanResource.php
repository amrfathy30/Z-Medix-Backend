<?php

namespace App\Filament\Resources;

use App\Enums\PlanFeature;
use App\Filament\Resources\PlanResource\Pages\CreatePlan;
use App\Filament\Resources\PlanResource\Pages\EditPlan;
use App\Filament\Resources\PlanResource\Pages\ListPlans;
use App\Models\Plan;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;

/**
 * The plans the platform sells: their features, the AI allowance, and one price
 * per billing cycle. A plan that was ever sold is only soft deleted, because the
 * periods paid for it keep pointing at it.
 */
class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'slug';

    public static function getNavigationLabel(): string
    {
        return __('admin.plans.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('admin.plans.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.plans.plural_model_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.subscriptions.navigation_group');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.plans.section_details'))
                    ->schema([
                        TextInput::make('slug')
                            ->label(__('admin.plans.field_slug'))
                            ->helperText(__('admin.plans.slug_hint'))
                            ->required()
                            ->alphaDash()
                            ->maxLength(64)
                            ->unique(ignoreRecord: true)
                            // The slug identifies the plan (the seeder matches on it),
                            // so it is fixed once the plan exists.
                            ->disabled(fn (string $operation): bool => $operation === 'edit'),
                        TextInput::make('sort_order')
                            ->label(__('admin.plans.field_sort_order'))
                            ->integer()
                            ->minValue(0)
                            ->default(0),
                        Toggle::make('is_active')
                            ->label(__('admin.plans.field_is_active'))
                            ->helperText(__('admin.plans.is_active_hint'))
                            ->default(true),
                        Tabs::make('Locales')
                            ->tabs([
                                Tab::make(__('admin.cms.tab_english'))
                                    ->schema([
                                        TextInput::make('name.en')
                                            ->label(__('admin.plans.field_name_en'))
                                            ->required()
                                            ->maxLength(120)
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(function (string $operation, ?string $state, Get $get, Set $set): void {
                                                if ($operation === 'create' && blank($get('slug'))) {
                                                    $set('slug', Str::slug((string) $state));
                                                }
                                            }),
                                        Textarea::make('description.en')
                                            ->label(__('admin.plans.field_description_en'))
                                            ->rows(3)
                                            ->maxLength(500),
                                    ]),
                                Tab::make(__('admin.cms.tab_arabic'))
                                    ->schema([
                                        TextInput::make('name.ar')
                                            ->label(__('admin.plans.field_name_ar'))
                                            ->maxLength(120),
                                        Textarea::make('description.ar')
                                            ->label(__('admin.plans.field_description_ar'))
                                            ->rows(3)
                                            ->maxLength(500),
                                    ]),
                            ])
                            ->columnSpanFull(),
                    ])
                    ->columns(3),

                Section::make(__('admin.plans.section_pricing'))
                    ->description(__('admin.plans.pricing_hint'))
                    ->schema([
                        TextInput::make('monthly_price')
                            ->label(__('admin.plans.field_monthly_price'))
                            ->numeric()
                            ->minValue(0)
                            ->requiredWithout('yearly_price'),
                        TextInput::make('yearly_price')
                            ->label(__('admin.plans.field_yearly_price'))
                            ->numeric()
                            ->minValue(0)
                            ->requiredWithout('monthly_price'),
                        TextInput::make('currency')
                            ->label(__('admin.plans.field_currency'))
                            ->length(3)
                            ->required()
                            ->default('USD')
                            ->dehydrateStateUsing(fn (?string $state): ?string => $state === null ? null : strtoupper($state)),
                    ])
                    ->columns(3),

                Section::make(__('admin.plans.section_features'))
                    ->description(__('admin.plans.features_hint'))
                    ->schema([
                        Repeater::make('featureLimits')
                            ->relationship()
                            ->hiddenLabel()
                            ->schema([
                                Select::make('feature')
                                    ->label(__('admin.plans.field_feature'))
                                    ->options(PlanFeature::options())
                                    ->required()
                                    ->live()
                                    ->distinct()
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                                Toggle::make('is_unlimited')
                                    ->label(__('admin.plans.field_is_unlimited'))
                                    ->live()
                                    ->visible(fn (Get $get): bool => self::isLimitable($get('feature'))),
                                TextInput::make('limit')
                                    ->label(__('admin.plans.field_limit'))
                                    ->helperText(__('admin.plans.limit_hint'))
                                    ->integer()
                                    ->minValue(1)
                                    ->visible(fn (Get $get): bool => self::isLimitable($get('feature')) && ! $get('is_unlimited'))
                                    ->required(fn (Get $get): bool => self::isLimitable($get('feature')) && ! $get('is_unlimited')),
                            ])
                            ->columns(3)
                            ->minItems(1)
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->addActionLabel(__('admin.plans.add_feature'))
                            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => self::normalizeFeatureData($data))
                            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => self::normalizeFeatureData($data)),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('featureLimits'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.plans.field_name'))
                    ->getStateUsing(fn (Plan $record): string => $record->getTranslation('name', 'en') ?: $record->getTranslation('name', 'ar'))
                    ->description(fn (Plan $record): string => $record->slug)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('name->en', 'like', "%{$search}%")
                        ->orWhere('name->ar', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%")),
                TextColumn::make('monthly_price')
                    ->label(__('admin.plans.field_monthly_price'))
                    ->placeholder('—')
                    ->suffix(fn (Plan $record): string => ' '.$record->currency)
                    ->sortable(),
                TextColumn::make('yearly_price')
                    ->label(__('admin.plans.field_yearly_price'))
                    ->placeholder('—')
                    ->suffix(fn (Plan $record): string => ' '.$record->currency)
                    ->sortable(),
                TextColumn::make('features')
                    ->label(__('admin.plans.section_features'))
                    ->badge()
                    ->getStateUsing(fn (Plan $record): array => $record->orderedFeatureLimits()
                        ->map(fn ($limit): string => $limit->feature->label())
                        ->all()),
                IconColumn::make('is_active')
                    ->label(__('admin.plans.field_is_active'))
                    ->boolean()
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->label(__('admin.plans.field_sort_order'))
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('admin.plans.field_is_active')),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->defaultSort('sort_order');
    }

    private static function isLimitable(mixed $feature): bool
    {
        return PlanFeature::tryFrom((string) $feature)?->isLimitable() ?? false;
    }

    /**
     * A limit only means something for a limitable feature, and "unlimited"
     * excludes a number. Hidden form fields are not submitted, so without this a
     * row switched from AI to another feature would keep its old limit.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function normalizeFeatureData(array $data): array
    {
        if (! self::isLimitable($data['feature'] ?? null)) {
            $data['is_unlimited'] = false;
            $data['limit'] = null;
        } elseif (! empty($data['is_unlimited'])) {
            $data['limit'] = null;
        }

        return $data;
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlans::route('/'),
            'create' => CreatePlan::route('/create'),
            'edit' => EditPlan::route('/{record}/edit'),
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
