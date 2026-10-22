<?php

namespace App\Listeners;

use App\Events\SubscriptionApproved;
use App\Notifications\InAppNotification;
use App\Support\Notifications\NotificationLocale;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendSubscriptionApprovedNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(SubscriptionApproved $event): void
    {
        $student = $event->student;
        $plan = $event->plan;
        $locale = NotificationLocale::forNotifiable($student);

        $student->notify(new InAppNotification(
            category: 'subscription',
            title: __('notifications.student.subscription_approved_title', [], $locale),
            body: __('notifications.student.subscription_approved_body', [
                'plan' => $plan->name,
            ], $locale),
            actionUrl: '/student/subscription',
            extra: [
                'period_id' => $event->period->id,
                'plan_id' => $plan->id,
            ],
            titleKey: 'notifications.student.subscription_approved_title',
            bodyKey: 'notifications.student.subscription_approved_body',
            replace: [
                'plan' => $plan->name,
            ],
            type: 'subscription_approved',
        ));
    }
}
