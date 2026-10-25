<?php

namespace App\Enums;

enum PromoCodeUsageStatus: string
{
    case Reserved = 'reserved';
    case Consumed = 'consumed';
    case Released = 'released';
}
