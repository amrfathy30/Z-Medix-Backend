<?php

namespace App\Enums;

enum OtpStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Expired = 'expired';
    case Failed = 'failed';
}
