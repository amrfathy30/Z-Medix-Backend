<?php

namespace App\Exceptions\Phone;

use RuntimeException;

class MaxPhoneNumbersReachedException extends RuntimeException
{
    public static function forOwner(int $max): self
    {
        return new self("This owner has reached the maximum of {$max} phone number(s).");
    }
}
