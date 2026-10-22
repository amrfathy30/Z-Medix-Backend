<?php

namespace App\Events;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SubscriptionRejected
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public User $student,
        public Plan $plan,
        public ?string $reason = null,
    ) {}
}
