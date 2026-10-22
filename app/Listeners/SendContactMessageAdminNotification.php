<?php

namespace App\Listeners;

use App\Enums\AccountStatus;
use App\Events\ContactMessageReceived;
use App\Models\Admin;
use App\Notifications\InAppNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;

class SendContactMessageAdminNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(ContactMessageReceived $event): void
    {
        $contact = $event->contactMessage;
        $admins = Admin::query()->where('status', AccountStatus::Active)->get();

        if ($admins->isEmpty()) {
            return;
        }

        $subjectPreview = (string) ($contact->subject ?: Str::limit((string) $contact->message, 50));

        foreach ($admins as $admin) {
            $locale = $admin->locale ?: 'en';

            $admin->notify(new InAppNotification(
                category: 'contact',
                title: __('notifications.admin.contact_title', [], $locale),
                body: __('notifications.admin.contact_body', [
                    'name' => $contact->name,
                    'subject' => $subjectPreview,
                ], $locale),
                actionUrl: "/admin/contact-messages/{$contact->id}",
                extra: [
                    'contact_message_id' => $contact->id,
                    'email' => $contact->email,
                ],
                titleKey: 'notifications.admin.contact_title',
                bodyKey: 'notifications.admin.contact_body',
                replace: [
                    'name' => $contact->name,
                    'subject' => $subjectPreview,
                ],
                type: 'contact_message',
            ));
        }
    }
}
