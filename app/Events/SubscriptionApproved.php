<?php

namespace App\Events;

use App\Models\Plan;
use App\Models\SubscriptionPeriod;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SubscriptionApproved
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public User $student,
        public Plan $plan,
        public SubscriptionPeriod $period,
    ) {}
}
