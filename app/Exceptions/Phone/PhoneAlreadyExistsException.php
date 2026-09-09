<?php

namespace App\Exceptions\Phone;

use RuntimeException;

class PhoneAlreadyExistsException extends RuntimeException
{
    public static function forOwner(string $e164): self
    {
        return new self("The phone number '{$e164}' is already registered for this owner.");
    }

    public static function globally(string $e164): self
    {
        return new self("The phone number '{$e164}' is already in use.");
    }
}
