<?php

namespace App\Filament\Pages\Auth;

use App\Enums\AdminType;
use App\Exceptions\Phone\InvalidPhoneException;
use App\Exceptions\Phone\MaxPhoneNumbersReachedException;
use App\Exceptions\Phone\PhoneAlreadyExistsException;
use App\Models\PhoneNumber;
use App\Services\Phone\PhoneNumberService;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use SensitiveParameter;

class EditProfile extends BaseEditProfile
{
    private const SUPPORTED_LOCALES = ['ar' => 'العربية', 'en' => 'English'];

    public static function getLabel(): string
    {
        return __('admin.profile.title');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.profile.section_basic_info'))
                    ->schema([
                        $this->getFirstNameFormComponent(),
                        $this->getLastNameFormComponent(),
                        $this->getEmailFormComponent(),
                        $this->getEmailCurrentPasswordFormComponent(),
                        $this->getJobTitleFormComponent(),
                        $this->getLocaleFormComponent(),
                    ]),
                Section::make(__('admin.profile.section_change_password'))
                    ->schema([
                        $this->getCurrentPasswordFormComponent(),
                        $this->getPasswordFormComponent(),
                        $this->getPasswordConfirmationFormComponent(),
                    ]),
                Section::make(__('admin.profile.section_phone_numbers'))
                    ->schema([
                        $this->getPhoneNumbersRepeaterComponent(),
                    ]),
            ]);
    }

    protected function getFirstNameFormComponent(): Component
    {
        return TextInput::make('first_name')
            ->label(__('admin.profile.field_first_name'))
            ->maxLength(255)
            ->nullable();
    }

    protected function getLastNameFormComponent(): Component
    {
        return TextInput::make('last_name')
            ->label(__('admin.profile.field_last_name'))
            ->maxLength(255)
            ->nullable();
    }

    protected function getEmailFormComponent(): Component
    {
        $isProtectedAdmin = $this->getUser()->type === AdminType::SuperAdmin;

        $component = parent::getEmailFormComponent()
            ->label(__('admin.profile.field_email'));

        if ($isProtectedAdmin) {
            $component = $component->disabled()->dehydrated(false);
        }

        return $component;
    }

    protected function getEmailCurrentPasswordFormComponent(): Component
    {
        return TextInput::make('emailCurrentPassword')
            ->label(__('admin.profile.field_current_password'))
            ->helperText(__('admin.profile.email_password_hint'))
            ->password()
            ->revealable()
            ->required()
            ->rules(['current_password:admin'])
            ->dehydrated(false)
            ->visible(fn (Get $get): bool => $get('email') !== $this->getUser()->getAttributeValue('email')
                && ! filled($get('password')));
    }

    protected function getJobTitleFormComponent(): Component
    {
        return TextInput::make('job_title')
            ->label(__('admin.profile.field_job_title'))
            ->maxLength(255)
            ->nullable();
    }

    protected function getLocaleFormComponent(): Component
    {
        return Select::make('locale')
            ->label(__('admin.profile.field_locale'))
            ->options(self::SUPPORTED_LOCALES)
            ->required();
    }

    protected function getCurrentPasswordFormComponent(): Component
    {
        return parent::getCurrentPasswordFormComponent()
            ->label(__('admin.profile.field_current_password'))
            ->visible(fn (Get $get): bool => filled($get('password')));
    }

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()
            ->label(__('admin.profile.field_new_password'));
    }

    protected function getPasswordConfirmationFormComponent(): Component
    {
        return parent::getPasswordConfirmationFormComponent()
            ->label(__('admin.profile.field_new_password_confirmation'));
    }

    protected function getPhoneNumbersRepeaterComponent(): Component
    {
        return Repeater::make('phone_numbers')
            ->label('')
            ->schema([
                Hidden::make('id'),
                TextInput::make('phone')
                    ->label(__('admin.profile.field_phone'))
                    ->placeholder('+20XXXXXXXXXX')
                    ->helperText(__('admin.profile.field_phone_hint'))
                    ->required()
                    ->maxLength(20),
                Toggle::make('is_primary')
                    ->label(__('admin.profile.field_is_primary'))
                    ->fixIndistinctState()
                    ->inline(false),
                TextInput::make('status')
                    ->label(__('admin.profile.field_phone_status'))
                    ->disabled()
                    ->dehydrated(false),
            ])
            ->columns(3)
            ->addActionLabel(__('admin.profile.phone_add'))
            ->reorderable(false)
            ->defaultItems(0);
    }

    protected function handleRecordUpdate(Model $record, #[SensitiveParameter] array $data): Model
    {
        $phoneNumbers = $data['phone_numbers'] ?? [];
        unset($data['phone_numbers']);

        $emailChanged = array_key_exists('email', $data) && $data['email'] !== $record->getAttributeValue('email');

        if ($emailChanged && $record->type === AdminType::SuperAdmin) {
            unset($data['email']);
        } elseif ($emailChanged && $record->email_verified_at !== null) {
            $data['email_verified_at'] = null;
        }

        $record->update($data);

        try {
            $this->syncPhoneNumbers($record, $phoneNumbers);
        } catch (MaxPhoneNumbersReachedException $e) {
            Notification::make()
                ->danger()
                ->title(__('admin.profile.phone_limit_reached'))
                ->body($e->getMessage())
                ->send();
            throw new Halt;
        } catch (PhoneAlreadyExistsException $e) {
            Notification::make()
                ->danger()
                ->title(__('admin.profile.phone_already_exists'))
                ->body($e->getMessage())
                ->send();
            throw new Halt;
        } catch (InvalidPhoneException $e) {
            Notification::make()
                ->danger()
                ->title(__('admin.profile.phone_invalid'))
                ->body($e->getMessage())
                ->send();
            throw new Halt;
        }

        return $record;
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('admin.profile.saved');
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['password']);

        $data['phone_numbers'] = $this->getUser()
            ->phoneNumbers()
            ->get()
            ->map(fn (PhoneNumber $phone) => [
                'id' => $phone->id,
                'phone' => $phone->e164_number,
                'is_primary' => $phone->is_primary,
                'status' => $phone->status->value,
            ])
            ->values()
            ->toArray();

        return $data;
    }

    private function syncPhoneNumbers(Model $admin, array $items): void
    {
        $existingIds = $admin->phoneNumbers()->pluck('id')->toArray();

        if (empty($existingIds) && empty($items)) {
            return;
        }

        $service = app(PhoneNumberService::class);
        $processedIds = [];
        $primaryPhoneId = null;

        foreach ($items as $item) {
            $id = isset($item['id']) && $item['id'] !== '' ? (int) $item['id'] : null;
            $phone = null;

            if ($id !== null) {
                $phone = PhoneNumber::find($id);

                if ($phone !== null) {
                    $processedIds[] = $id;

                    if ($phone->e164_number !== $item['phone']) {
                        $service->updatePhone($phone, [
                            'phone' => $item['phone'],
                            'country' => $phone->country_iso2 ?? config('phone.default_country'),
                        ]);
                        $phone->refresh();
                    }
                }
            } else {
                $phone = $service->addPhone($admin, [
                    'phone' => $item['phone'],
                    'country' => config('phone.default_country'),
                ]);
                $processedIds[] = $phone->id;
            }

            if ($phone !== null && ($item['is_primary'] ?? false)) {
                $primaryPhoneId = $phone->id;
            }
        }

        foreach (array_diff($existingIds, $processedIds) as $deletedId) {
            $phone = PhoneNumber::find($deletedId);
            if ($phone !== null) {
                $service->deletePhone($phone);
            }
        }

        if ($primaryPhoneId !== null) {
            $phone = PhoneNumber::find($primaryPhoneId);
            if ($phone !== null) {
                $service->setPrimary($phone);
            }
        }
    }
}
