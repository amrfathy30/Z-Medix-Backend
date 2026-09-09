<?php

namespace App\Enums;

enum AccountStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Inactive = 'inactive';
    case Suspended = 'suspended';
    case Blocked = 'blocked';
}
