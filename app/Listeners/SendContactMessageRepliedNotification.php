<?php

namespace App\Listeners;

use App\Events\ContactMessageReplied;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Support\Notifications\NotificationLocale;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;

class SendContactMessageRepliedNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(ContactMessageReplied $event): void
    {
        $contact = $event->contactMessage;
        $student = User::query()->where('email', $contact->email)->first();

        if ($student === null) {
            return;
        }

        $locale = NotificationLocale::forNotifiable($student);
        $subjectPreview = (string) ($contact->subject ?: Str::limit((string) $contact->message, 40));

        $student->notify(new InAppNotification(
            category: 'contact',
            title: __('notifications.student.contact_replied_title', [], $locale),
            body: __('notifications.student.contact_replied_body', [
                'subject' => $subjectPreview,
            ], $locale),
            actionUrl: '/contact',
            extra: [
                'contact_message_id' => $contact->id,
                'reply' => $contact->admin_reply,
            ],
            titleKey: 'notifications.student.contact_replied_title',
            bodyKey: 'notifications.student.contact_replied_body',
            replace: [
                'subject' => $subjectPreview,
            ],
            type: 'contact_replied',
        ));
    }
}
