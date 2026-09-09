<?php

namespace App\Enums;

enum PhoneStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Inactive = 'inactive';
    case Blocked = 'blocked';
}
