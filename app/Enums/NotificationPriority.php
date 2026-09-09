<?php

namespace App\Enums;

enum NotificationPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Critical = 'critical';
}
