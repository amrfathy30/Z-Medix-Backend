<?php

namespace App\Filament\Pages;

use App\Support\Fellowship\FellowshipSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The one platform-wide Fellowship setting: the advisory per-question time
 * limit.
 *
 * Global rather than per-Fellowship by design, and snapshotted into each session
 * at generation — so changing it here applies to sessions generated from now on
 * and never re-judges one already running.
 *
 * It is advisory in the strongest sense: a question answered after the limit is
 * recorded as late and graded exactly as an on-time answer. Nothing about the
 * limit touches a score.
 *
 * There is no total session time limit, and nothing here can create one.
 *
 * @property-read Schema $form
 */
class FellowshipSettingsPage extends Page
{
    protected string $view = 'filament.pages.fellowship-settings-page';

    protected static ?string $slug = 'fellowship-settings';

    protected static ?int $navigationSort = 4;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('admin.fellowship.settings_navigation_label');
    }

    public static function getNavigationIcon(): string|\BackedEnum|null
    {
        return Heroicon::OutlinedClock;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.learning.navigation_group');
    }

    public static function canAccess(): bool
    {
        $admin = auth('admin')->user();

        return $admin !== null && $admin->hasPermissionTo('fellowships.view');
    }

    public function getTitle(): string|Htmlable
    {
        return __('admin.fellowship.settings_title');
    }

    public function mount(): void
    {
        $this->form->fill([
            'question_time_limit_seconds' => FellowshipSettings::questionTimeLimitSeconds(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.fellowship.settings_section_timing'))
                    ->description(__('admin.fellowship.settings_section_timing_hint'))
                    ->schema([
                        TextInput::make('question_time_limit_seconds')
                            ->label(__('admin.fellowship.field_question_time_limit'))
                            ->helperText(__('admin.fellowship.question_time_limit_hint'))
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->maxValue(3600)
                            ->suffix(__('admin.fellowship.unit_seconds')),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $admin = auth('admin')->user();

        if ($admin === null || ! $admin->hasPermissionTo('fellowships.update')) {
            Notification::make()->danger()->title(__('admin.fellowship.settings_not_permitted'))->send();

            return;
        }

        $data = $this->form->getState();

        FellowshipSettings::setQuestionTimeLimitSeconds((int) $data['question_time_limit_seconds']);

        Notification::make()->success()->title(__('admin.fellowship.settings_saved'))->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label(__('admin.fellowship.action_save_settings'))
                ->action('save')
                ->visible(fn (): bool => auth('admin')->user()?->hasPermissionTo('fellowships.update') ?? false),
        ];
    }
}
