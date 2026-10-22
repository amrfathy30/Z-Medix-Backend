<?php

namespace App\Events;

use App\Models\ContactMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ContactMessageReplied
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ContactMessage $contactMessage,
    ) {}
}
