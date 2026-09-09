<x-filament-panels::page>

    {{-- ===== Roles section ===== --}}
    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="fi-section-header flex flex-wrap items-center gap-x-3 gap-y-1 px-6 py-4">
            <div class="grid flex-1 gap-y-1">
                <h3 class="fi-section-header-heading text-base font-semibold leading-6 text-gray-950 dark:text-white">
                    {{ __('admin.access_management.roles_section') }}
                </h3>
            </div>
            <div>
                {{ $this->createRoleAction }}
            </div>
        </div>

        <div class="fi-section-content px-6 pb-6">
            @php $roles = $this->getRoles(); @endphp

            @if ($roles->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('admin.access_management.no_roles') }}</p>
            @else
                <div class="grid w-full grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($roles as $role)
                        <div class="flex min-h-[220px] max-h-[260px] flex-col rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">

                            {{-- Card header: role name + edit/delete --}}
                            <div class="flex items-start justify-between gap-3">
                                <h4 class="text-base font-semibold text-gray-900 dark:text-white">
                                    {{ $this->getRoleDisplayName($role) }}
                                </h4>
                                <div class="flex shrink-0 gap-2">
                                    {{ ($this->editRoleAction)(['roleId' => $role->id]) }}
                                    {{ ($this->deleteRoleAction)(['roleId' => $role->id]) }}
                                </div>
                            </div>

                            {{-- Card body: accent line + scrollable permissions --}}
                            <div class="mt-4 flex min-h-0 flex-1 gap-3">
                                <div class="w-1 shrink-0 rounded-full bg-primary-500"></div>

                                <div class="min-h-0 flex-1 overflow-y-auto pe-2">
                                    @if ($role->permissions->isNotEmpty())
                                        <ul class="space-y-1.5">
                                            @foreach ($role->permissions as $permission)
                                                <li class="flex items-center gap-1.5 text-sm text-gray-700 dark:text-gray-300">
                                                    <span class="shrink-0 text-sm leading-none text-success-500">✓</span>
                                                    {{ $this->getPermissionLabel($permission) }}
                                                </li>
                                            @endforeach
                                        </ul>
                                    @else
                                        <p class="text-xs text-gray-400 dark:text-gray-500">{{ __('admin.access_management.no_permissions') }}</p>
                                    @endif
                                </div>
                            </div>

                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- ===== Admins table ===== --}}
    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="fi-section-header flex flex-wrap items-center gap-x-3 gap-y-1 px-6 py-4">
            <div class="grid flex-1 gap-y-1">
                <h3 class="fi-section-header-heading text-base font-semibold leading-6 text-gray-950 dark:text-white">
                    {{ __('admin.access_management.admins_section') }}
                </h3>
            </div>
            <div>
                {{ $this->createAdminAction }}
            </div>
        </div>
        <div class="access-management-admins-table">
            {{ $this->getTable() }}
        </div>
    </div>

</x-filament-panels::page>
