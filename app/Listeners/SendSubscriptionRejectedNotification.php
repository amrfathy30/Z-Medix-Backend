<?php

namespace App\Listeners;

use App\Events\SubscriptionRejected;
use App\Notifications\InAppNotification;
use App\Support\Notifications\NotificationLocale;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendSubscriptionRejectedNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(SubscriptionRejected $event): void
    {
        $student = $event->student;
        $plan = $event->plan;
        $locale = NotificationLocale::forNotifiable($student);
        $reason = $event->reason ?: __('subscriptions.default_rejection_reason');

        $student->notify(new InAppNotification(
            category: 'subscription',
            title: __('notifications.student.subscription_rejected_title', [], $locale),
            body: __('notifications.student.subscription_rejected_body', [
                'plan' => $plan->name,
                'reason' => $reason,
            ], $locale),
            actionUrl: '/student/plans',
            extra: [
                'plan_id' => $plan->id,
                'reason' => $reason,
            ],
            titleKey: 'notifications.student.subscription_rejected_title',
            bodyKey: 'notifications.student.subscription_rejected_body',
            replace: [
                'plan' => $plan->name,
                'reason' => $reason,
            ],
            type: 'subscription_rejected',
        ));
    }
}
