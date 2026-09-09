<?php

namespace App\Exceptions\Phone;

use InvalidArgumentException;

class InvalidPhoneException extends InvalidArgumentException
{
    public static function unsupportedCountry(string $country): self
    {
        return new self("Phone number country '{$country}' is not allowed.");
    }

    public static function invalidNumber(string $phone): self
    {
        return new self("The phone number '{$phone}' is not valid.");
    }
}
