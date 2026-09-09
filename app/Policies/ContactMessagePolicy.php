<?php

namespace App\Policies;

use App\Models\Admin;
use App\Models\ContactMessage;

class ContactMessagePolicy
{
    public function viewAny(Admin $admin): bool
    {
        return $admin->hasPermissionTo('contact_messages.view');
    }

    public function view(Admin $admin, ContactMessage $contactMessage): bool
    {
        return $admin->hasPermissionTo('contact_messages.view');
    }

    public function create(Admin $admin): bool
    {
        return false;
    }

    public function update(Admin $admin, ContactMessage $contactMessage): bool
    {
        return $admin->hasPermissionTo('contact_messages.update');
    }

    public function delete(Admin $admin, ContactMessage $contactMessage): bool
    {
        return $admin->hasPermissionTo('contact_messages.delete');
    }

    public function restore(Admin $admin, ContactMessage $contactMessage): bool
    {
        return $admin->hasPermissionTo('contact_messages.delete');
    }

    public function forceDelete(Admin $admin, ContactMessage $contactMessage): bool
    {
        return $admin->hasPermissionTo('contact_messages.delete');
    }
}
