<?php

namespace App\Listeners;

use App\Enums\AccountStatus;
use App\Events\StudentRegistered;
use App\Events\StudentSubscribed;
use App\Models\Admin;
use App\Notifications\InAppNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendSubscriptionAdminNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(StudentRegistered|StudentSubscribed $event): void
    {
        $user = $event->user;
        $admins = Admin::query()->where('status', AccountStatus::Active)->get();

        if ($admins->isEmpty()) {
            return;
        }

        foreach ($admins as $admin) {
            $locale = $admin->locale ?: 'en';

            $admin->notify(new InAppNotification(
                category: 'subscription',
                title: __('notifications.admin.subscription_title', [], $locale),
                body: __('notifications.admin.subscription_body', [
                    'name' => $user->name,
                ], $locale),
                actionUrl: "/admin/users/{$user->id}",
                extra: [
                    'user_id' => $user->id,
                    'email' => $user->email,
                ],
                titleKey: 'notifications.admin.subscription_title',
                bodyKey: 'notifications.admin.subscription_body',
                replace: [
                    'name' => $user->name,
                ],
                type: 'new_subscription',
            ));
        }
    }
}
