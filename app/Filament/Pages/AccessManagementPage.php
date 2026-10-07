<?php

namespace App\Filament\Pages;

use App\Enums\AccountStatus;
use App\Enums\AdminType;
use App\Models\Admin;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AccessManagementPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.access-management-page';

    protected static ?string $slug = 'permissions';

    protected static ?int $navigationSort = 10;

    private const SUPER_ADMIN_ROLE = 'super_admin';

    public ?int $editingRoleId = null;

    public ?int $editingAdminId = null;

    /**
     * The page manages employees and the roles assigned to them, so reaching it
     * at all requires the admins.view permission. Each action below is gated by
     * its own permission, so a role that may list employees without creating,
     * suspending or deleting them sees only what it holds.
     */
    public static function canAccess(): bool
    {
        return auth('admin')->check() && self::adminCan('admins.view');
    }

    private static function adminCan(string $permission): bool
    {
        return (bool) auth('admin')->user()?->hasPermissionTo($permission);
    }

    /**
     * Super admin is never handed out by someone who is not already one, no
     * matter which admins.* permissions they hold. This is identity, not a
     * permission: it cannot be granted through a role.
     */
    private static function actorIsSuperAdmin(): bool
    {
        return auth('admin')->user()?->type === AdminType::SuperAdmin;
    }

    /**
     * A super admin account is read-only to anyone who is not a super admin.
     * Name, status and every other mutable field are covered, not only the
     * email/type/role the edit form already froze.
     */
    private static function isProtectedFromActor(Admin $record): bool
    {
        return $record->type === AdminType::SuperAdmin && ! self::actorIsSuperAdmin();
    }

    /**
     * Refuses an attempt to modify a super admin account from an actor who is
     * not one. The record is read from the database, so a crafted payload is
     * refused on the same terms as a form submission.
     */
    private function refusesProtectedAdminChange(Admin $record): bool
    {
        if (! self::isProtectedFromActor($record)) {
            return false;
        }

        Notification::make()
            ->title(__('admin.access_management.cannot_modify_super_admin'))
            ->body(__('admin.access_management.cannot_modify_super_admin_body'))
            ->danger()
            ->send();

        return true;
    }

    /**
     * Refuses a submitted payload that would make a non-super-admin account a
     * super admin. The check reads the payload itself rather than the form, so
     * a crafted request that never saw the filtered options is refused too.
     *
     * An account that is already a super admin is not an escalation: the edit
     * action freezes its type, email and role, so nothing is being granted.
     *
     * @param  array<string, mixed>  $data
     */
    private function refusesSuperAdminEscalation(array $data, ?Admin $record = null): bool
    {
        if (self::actorIsSuperAdmin() || $record?->type === AdminType::SuperAdmin) {
            return false;
        }

        $grantsType = ($data['type'] ?? null) === AdminType::SuperAdmin->value;
        $grantsRole = ($data['role'] ?? null) === self::SUPER_ADMIN_ROLE;

        if (! $grantsType && ! $grantsRole) {
            return false;
        }

        Notification::make()
            ->title(__('admin.access_management.cannot_grant_super_admin'))
            ->body(__('admin.access_management.cannot_grant_super_admin_body'))
            ->danger()
            ->send();

        return true;
    }

    /**
     * The roles section lists every role with the permissions it holds, which is
     * what roles.view guards. The page itself stays reachable without it, on the
     * admins.view permission alone.
     */
    public function canViewRoles(): bool
    {
        return self::adminCan('roles.view');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.access_management.navigation_label');
    }

    public static function getNavigationIcon(): string|\BackedEnum|null
    {
        return 'heroicon-o-shield-check';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.access_management.navigation_group');
    }

    public function getTitle(): string|Htmlable
    {
        return __('admin.access_management.title');
    }

    public function getRoles(): Collection
    {
        return Role::where('guard_name', 'admin')
            ->with('permissions')
            ->get();
    }

    public function getRoleDisplayName(Role $role): string
    {
        $locale = app()->getLocale();

        if ($locale === 'ar' && filled($role->display_name_ar)) {
            return $role->display_name_ar;
        }

        if ($locale === 'en' && filled($role->display_name_en)) {
            return $role->display_name_en;
        }

        return $role->name;
    }

    public function getLocalizedRoleName(string $roleName): string
    {
        $locale = app()->getLocale();
        $role = Role::where('guard_name', 'admin')->where('name', $roleName)->first();

        if (! $role) {
            return $roleName;
        }

        if ($locale === 'ar' && filled($role->display_name_ar)) {
            return $role->display_name_ar;
        }

        if ($locale === 'en' && filled($role->display_name_en)) {
            return $role->display_name_en;
        }

        return $role->name;
    }

    public function getPermissionLabel(Permission $permission): string
    {
        $locale = app()->getLocale();

        if ($locale === 'ar' && filled($permission->display_name_ar)) {
            return $permission->display_name_ar;
        }

        if ($locale === 'en' && filled($permission->display_name_en)) {
            return $permission->display_name_en;
        }

        return $permission->name;
    }

    private function getPermissionsOptions(): array
    {
        return Permission::where('guard_name', 'admin')
            ->get()
            ->mapWithKeys(fn (Permission $p) => [$p->name => $this->getPermissionLabel($p)])
            ->toArray();
    }

    /**
     * @return array<string, string>
     */
    private function getRolesOptions(bool $includeSuperAdmin = true): array
    {
        return Role::where('guard_name', 'admin')
            ->get()
            ->reject(fn (Role $r): bool => $r->name === self::SUPER_ADMIN_ROLE && ! $includeSuperAdmin)
            ->mapWithKeys(fn (Role $r) => [$r->name => $this->getRoleDisplayName($r)])
            ->toArray();
    }

    /**
     * @return array<string, string>
     */
    private function getAdminTypeOptions(bool $includeSuperAdmin = true): array
    {
        $options = [
            AdminType::Admin->value => __('admin.access_management.type_admin'),
        ];

        if ($includeSuperAdmin) {
            $options[AdminType::SuperAdmin->value] = __('admin.access_management.type_super_admin');
        }

        return $options;
    }

    public static function getAdminTablePageOptions(): array
    {
        return [5, 10, 25, 50];
    }

    private function getAdminStatusOptions(): array
    {
        return [
            AccountStatus::Active->value => __('admin.access_management.status_active'),
            AccountStatus::Suspended->value => __('admin.access_management.status_suspended'),
            AccountStatus::Pending->value => __('admin.access_management.status_pending'),
            AccountStatus::Inactive->value => __('admin.access_management.status_inactive'),
            AccountStatus::Blocked->value => __('admin.access_management.status_blocked'),
        ];
    }

    private function generateUniqueName(string $displayNameEn): string
    {
        $base = Str::snake(preg_replace('/[^a-zA-Z0-9\s]/', '', $displayNameEn));
        $name = $base;
        $counter = 2;

        while (Role::where('name', $name)->where('guard_name', 'admin')->exists()) {
            $name = $base.'_'.$counter++;
        }

        return $name;
    }

    // ─── Role actions ────────────────────────────────────────────────────────────

    public function createRoleAction(): Action
    {
        return Action::make('createRole')
            ->label(__('admin.access_management.create_role'))
            ->authorize(fn (): bool => self::adminCan('roles.create'))
            ->modalHeading(__('admin.access_management.create_role_heading'))
            ->modalSubmitActionLabel(__('admin.access_management.save'))
            ->schema([
                Grid::make(2)
                    ->schema([
                        TextInput::make('display_name_ar')
                            ->label(__('admin.access_management.arabic_role_name'))
                            ->required(),
                        TextInput::make('display_name_en')
                            ->label(__('admin.access_management.english_role_name'))
                            ->required(),
                    ]),
                CheckboxList::make('permissions')
                    ->label(__('admin.access_management.permissions'))
                    ->options(fn () => $this->getPermissionsOptions())
                    ->columns(2),
            ])
            ->action(function (array $data): void {
                $name = $this->generateUniqueName($data['display_name_en']);

                $role = Role::create([
                    'name' => $name,
                    'guard_name' => 'admin',
                    'display_name_ar' => $data['display_name_ar'],
                    'display_name_en' => $data['display_name_en'],
                ]);

                $permissions = Permission::where('guard_name', 'admin')
                    ->whereIn('name', $data['permissions'] ?? [])
                    ->get();

                $role->syncPermissions($permissions);

                app(PermissionRegistrar::class)->forgetCachedPermissions();

                Notification::make()
                    ->title(__('admin.access_management.role_created'))
                    ->success()
                    ->send();
            });
    }

    public function editRoleAction(): Action
    {
        return Action::make('editRole')
            ->label(__('admin.access_management.edit_role'))
            ->authorize(fn (): bool => self::adminCan('roles.update'))
            ->color('warning')
            ->button()
            ->outlined()
            ->size(Size::Small)
            ->extraAttributes(['class' => 'fi-btn-admin-filled-hover'])
            ->modalHeading(__('admin.access_management.edit_role_heading'))
            ->modalSubmitActionLabel(__('admin.access_management.save'))
            ->mountUsing(function (Schema $form, array $arguments): void {
                $this->editingRoleId = $arguments['roleId'];
                $role = Role::with('permissions')->find($arguments['roleId']);
                $form->fill([
                    'display_name_ar' => $role->display_name_ar,
                    'display_name_en' => $role->display_name_en,
                    'permissions' => $role->permissions->pluck('name')->toArray(),
                ]);
            })
            ->schema([
                Grid::make(2)
                    ->schema([
                        TextInput::make('display_name_ar')
                            ->label(__('admin.access_management.arabic_role_name'))
                            ->required(),
                        TextInput::make('display_name_en')
                            ->label(__('admin.access_management.english_role_name'))
                            ->required(),
                    ]),
                CheckboxList::make('permissions')
                    ->label(__('admin.access_management.permissions'))
                    ->options(fn () => $this->getPermissionsOptions())
                    ->columns(2),
            ])
            ->action(function (array $data, array $arguments): void {
                $role = Role::find($arguments['roleId']);

                $role->display_name_ar = $data['display_name_ar'];
                $role->display_name_en = $data['display_name_en'];
                $role->save();

                $permissions = Permission::where('guard_name', 'admin')
                    ->whereIn('name', $data['permissions'] ?? [])
                    ->get();

                $role->syncPermissions($permissions);

                app(PermissionRegistrar::class)->forgetCachedPermissions();

                Notification::make()
                    ->title(__('admin.access_management.role_updated'))
                    ->success()
                    ->send();
            });
    }

    public function deleteRoleAction(): Action
    {
        return Action::make('deleteRole')
            ->label(__('admin.access_management.delete_role'))
            ->authorize(fn (): bool => self::adminCan('roles.delete'))
            ->color('danger')
            ->button()
            ->outlined()
            ->size(Size::Small)
            ->extraAttributes(['class' => 'fi-btn-admin-filled-hover'])
            ->requiresConfirmation()
            ->modalHeading(__('admin.access_management.delete_role_heading'))
            ->modalDescription(__('admin.access_management.delete_role_description'))
            ->modalSubmitActionLabel(__('admin.access_management.delete_role_confirm'))
            ->action(function (array $arguments): void {
                $role = Role::find($arguments['roleId']);

                if (! $role) {
                    return;
                }

                if ($role->name === 'super_admin') {
                    Notification::make()
                        ->title(__('admin.access_management.cannot_delete_super_admin'))
                        ->body(__('admin.access_management.cannot_delete_super_admin_body'))
                        ->danger()
                        ->send();

                    return;
                }

                $hasAdmins = Admin::whereHas(
                    'roles',
                    fn ($q) => $q->where('id', $role->id)
                )->exists();

                if ($hasAdmins) {
                    Notification::make()
                        ->title(__('admin.access_management.cannot_delete_role_with_admins'))
                        ->body(__('admin.access_management.cannot_delete_role_with_admins_body'))
                        ->danger()
                        ->send();

                    return;
                }

                $role->delete();

                app(PermissionRegistrar::class)->forgetCachedPermissions();

                Notification::make()
                    ->title(__('admin.access_management.role_deleted'))
                    ->success()
                    ->send();
            });
    }

    // ─── Admin actions ───────────────────────────────────────────────────────────

    public function createAdminAction(): Action
    {
        return Action::make('createAdmin')
            ->label(__('admin.access_management.create_admin'))
            ->authorize(fn (): bool => self::adminCan('admins.create'))
            ->modalHeading(__('admin.access_management.create_admin_heading'))
            ->modalSubmitActionLabel(__('admin.access_management.save'))
            ->schema([
                Grid::make(2)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('admin.access_management.field_name'))
                            ->required(),
                        TextInput::make('email')
                            ->label(__('admin.access_management.field_email'))
                            ->email()
                            ->required()
                            ->unique(table: Admin::class, column: 'email'),
                    ]),
                Grid::make(2)
                    ->schema([
                        TextInput::make('password')
                            ->label(__('admin.access_management.field_password'))
                            ->password()
                            ->required()
                            ->minLength(8),
                        TextInput::make('password_confirmation')
                            ->label(__('admin.access_management.field_password_confirmation'))
                            ->password()
                            ->required()
                            ->same('password'),
                    ]),
                Grid::make(2)
                    ->schema([
                        Select::make('type')
                            ->label(__('admin.access_management.field_type'))
                            ->options(fn () => $this->getAdminTypeOptions(self::actorIsSuperAdmin()))
                            ->default(AdminType::Admin->value)
                            ->required(),
                        Select::make('status')
                            ->label(__('admin.access_management.field_status'))
                            ->options(fn () => $this->getAdminStatusOptions())
                            ->default(AccountStatus::Active->value)
                            ->required(),
                    ]),
                Select::make('role')
                    ->label(__('admin.access_management.field_role'))
                    ->options(fn () => $this->getRolesOptions(self::actorIsSuperAdmin()))
                    ->required(),
            ])
            ->action(function (array $data): void {
                if ($this->refusesSuperAdminEscalation($data)) {
                    return;
                }

                $admin = Admin::create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => $data['password'],
                    'type' => $data['type'],
                    'status' => $data['status'],
                ]);

                $admin->syncRoles([$data['role']]);

                app(PermissionRegistrar::class)->forgetCachedPermissions();

                Notification::make()
                    ->title(__('admin.access_management.admin_created'))
                    ->success()
                    ->send();
            });
    }

    // ─── Table ───────────────────────────────────────────────────────────────────

    public function table(Table $table): Table
    {
        return $table
            ->query(Admin::query()->with('roles')->latest())
            ->paginationPageOptions(static::getAdminTablePageOptions())
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.access_management.column_name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label(__('admin.access_management.column_email'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('role')
                    ->label(__('admin.access_management.column_role'))
                    ->state(fn (Admin $record): ?string => $record->roles->first()?->name)
                    ->formatStateUsing(fn (?string $state): string => $state
                        ? $this->getLocalizedRoleName($state)
                        : '—'
                    )
                    ->badge()
                    ->color('primary'),

                TextColumn::make('status')
                    ->label(__('admin.access_management.column_status'))
                    ->badge()
                    ->formatStateUsing(fn (AccountStatus $state): string => match ($state) {
                        AccountStatus::Active => __('admin.access_management.status_active'),
                        AccountStatus::Suspended => __('admin.access_management.status_suspended'),
                        AccountStatus::Blocked => __('admin.access_management.status_blocked'),
                        AccountStatus::Pending => __('admin.access_management.status_pending'),
                        AccountStatus::Inactive => __('admin.access_management.status_inactive'),
                    })
                    ->color(fn (AccountStatus $state): string => match ($state) {
                        AccountStatus::Active => 'success',
                        AccountStatus::Suspended, AccountStatus::Blocked => 'danger',
                        AccountStatus::Pending, AccountStatus::Inactive => 'gray',
                    }),
            ])
            ->recordActions([
                Action::make('editAdmin')
                    ->label(__('admin.access_management.edit_admin'))
                    ->authorize(fn (Admin $record): bool => self::adminCan('admins.update')
                        && ! self::isProtectedFromActor($record))
                    ->icon('heroicon-o-pencil')
                    ->color('warning')
                    ->button()
                    ->outlined()
                    ->size(Size::Small)
                    ->extraAttributes(['class' => 'fi-btn-admin-filled-hover'])
                    ->modalHeading(__('admin.access_management.edit_admin_heading'))
                    ->modalSubmitActionLabel(__('admin.access_management.save'))
                    ->fillForm(function (Admin $record): array {
                        $this->editingAdminId = $record->id;

                        return [
                            'name' => $record->name,
                            'email' => $record->email,
                            'type' => $record->type->value,
                            'status' => $record->status->value,
                            'role' => $record->roles->first()?->name,
                            '_is_root_admin' => $record->type === AdminType::SuperAdmin,
                        ];
                    })
                    ->schema([
                        Hidden::make('_admin_id'),
                        Hidden::make('_is_root_admin'),
                        Grid::make(2)
                            ->schema([
                                TextInput::make('name')
                                    ->label(__('admin.access_management.field_name'))
                                    ->required(),
                                TextInput::make('email')
                                    ->label(__('admin.access_management.field_email'))
                                    ->email()
                                    ->required()
                                    ->unique(
                                        table: Admin::class,
                                        column: 'email',
                                        ignorable: fn () => Admin::find($this->editingAdminId)
                                    )
                                    ->disabled(fn (Get $get): bool => (bool) $get('_is_root_admin'))
                                    ->dehydrated(true),
                            ]),
                        Grid::make(2)
                            ->schema([
                                TextInput::make('password')
                                    ->label(__('admin.access_management.field_password'))
                                    ->password()
                                    ->minLength(8)
                                    ->nullable()
                                    ->dehydrated(fn (?string $state): bool => filled($state)),
                                TextInput::make('password_confirmation')
                                    ->label(__('admin.access_management.field_password_confirmation'))
                                    ->password()
                                    ->nullable()
                                    ->requiredWith('password')
                                    ->same('password')
                                    ->dehydrated(false),
                            ]),
                        Grid::make(2)
                            ->schema([
                                Select::make('type')
                                    ->label(__('admin.access_management.field_type'))
                                    ->options(fn (Get $get) => $this->getAdminTypeOptions(
                                        self::actorIsSuperAdmin() || (bool) $get('_is_root_admin'),
                                    ))
                                    ->required()
                                    ->disabled(fn (Get $get): bool => (bool) $get('_is_root_admin'))
                                    ->dehydrated(true),
                                Select::make('status')
                                    ->label(__('admin.access_management.field_status'))
                                    ->options(fn () => $this->getAdminStatusOptions())
                                    ->required(),
                            ]),
                        Select::make('role')
                            ->label(__('admin.access_management.field_role'))
                            ->options(fn (Get $get) => $this->getRolesOptions(
                                self::actorIsSuperAdmin() || (bool) $get('_is_root_admin'),
                            ))
                            ->required()
                            ->disabled(fn (Get $get): bool => (bool) $get('_is_root_admin'))
                            ->dehydrated(true),
                    ])
                    ->action(function (array $data, Admin $record): void {
                        if ($this->refusesProtectedAdminChange($record)) {
                            return;
                        }

                        if ($this->refusesSuperAdminEscalation($data, $record)) {
                            return;
                        }

                        // Capture identity from DB before any mutation.
                        $isRootAdmin = $record->type === AdminType::SuperAdmin;

                        $updateData = [
                            'name' => $data['name'],
                            'email' => $isRootAdmin ? $record->email : $data['email'],
                            'type' => $isRootAdmin ? AdminType::SuperAdmin->value : $data['type'],
                            'status' => $data['status'],
                        ];

                        if (filled($data['password'] ?? null)) {
                            $updateData['password'] = $data['password'];
                        }

                        $record->update($updateData);

                        $roleToSync = $isRootAdmin ? 'super_admin' : ($data['role'] ?? null);

                        if (filled($roleToSync)) {
                            $record->syncRoles([$roleToSync]);
                        }

                        app(PermissionRegistrar::class)->forgetCachedPermissions();

                        Notification::make()
                            ->title(__('admin.access_management.admin_updated'))
                            ->success()
                            ->send();
                    }),

                Action::make('toggleStatus')
                    ->label(fn (Admin $record): string => $record->status === AccountStatus::Active
                        ? __('admin.access_management.suspend_admin')
                        : __('admin.access_management.activate_admin'))
                    ->authorize(fn (Admin $record): bool => self::adminCan('admins.update')
                        && ! self::isProtectedFromActor($record))
                    ->icon(fn (Admin $record): string => $record->status === AccountStatus::Active
                        ? 'heroicon-o-no-symbol'
                        : 'heroicon-o-check-circle')
                    ->color(fn (Admin $record): string => $record->status === AccountStatus::Active
                        ? 'danger'
                        : 'success')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Admin $record): string => $record->status === AccountStatus::Active
                        ? __('admin.access_management.suspend_admin_heading')
                        : __('admin.access_management.activate_admin_heading'))
                    ->modalDescription(fn (Admin $record): string => $record->status === AccountStatus::Active
                        ? __('admin.access_management.suspend_admin_description')
                        : __('admin.access_management.activate_admin_description'))
                    ->modalSubmitActionLabel(fn (Admin $record): string => $record->status === AccountStatus::Active
                        ? __('admin.access_management.suspend_admin_confirm')
                        : __('admin.access_management.activate_admin_confirm'))
                    ->hidden(fn (Admin $record): bool => $record->id === auth('admin')->id())
                    ->action(function (Admin $record): void {
                        if ($this->refusesProtectedAdminChange($record)) {
                            return;
                        }

                        if ($record->id === auth('admin')->id()) {
                            Notification::make()
                                ->title(__('admin.access_management.cannot_self_suspend'))
                                ->danger()
                                ->send();

                            return;
                        }

                        $newStatus = $record->status === AccountStatus::Active
                            ? AccountStatus::Suspended
                            : AccountStatus::Active;

                        $record->update(['status' => $newStatus]);

                        $notificationKey = $newStatus === AccountStatus::Active
                            ? 'admin_activated'
                            : 'admin_suspended';

                        Notification::make()
                            ->title(__("admin.access_management.{$notificationKey}"))
                            ->success()
                            ->send();
                    }),

                Action::make('deleteAdmin')
                    ->label(__('admin.access_management.delete_admin'))
                    ->authorize(fn (Admin $record): bool => self::adminCan('admins.delete')
                        && ! self::isProtectedFromActor($record))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('admin.access_management.delete_admin_heading'))
                    ->modalDescription(__('admin.access_management.delete_admin_description'))
                    ->modalSubmitActionLabel(__('admin.access_management.delete_admin_confirm'))
                    ->hidden(fn (Admin $record): bool => $record->id === auth('admin')->id()
                        || $record->type === AdminType::SuperAdmin)
                    ->action(function (Admin $record): void {
                        if ($record->id === auth('admin')->id()) {
                            Notification::make()
                                ->title(__('admin.access_management.cannot_self_delete'))
                                ->danger()
                                ->send();

                            return;
                        }

                        if ($record->type === AdminType::SuperAdmin) {
                            Notification::make()
                                ->title(__('admin.access_management.cannot_delete_protected_admin'))
                                ->danger()
                                ->send();

                            return;
                        }

                        $record->delete();

                        Notification::make()
                            ->title(__('admin.access_management.admin_deleted'))
                            ->success()
                            ->send();
                    }),
            ])
            ->striped()
            ->defaultSort('name');
    }
}
