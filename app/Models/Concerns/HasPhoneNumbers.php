<?php

namespace App\Models\Concerns;

use App\Models\PhoneNumber;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasPhoneNumbers
{
    public function phoneNumbers(): MorphMany
    {
        return $this->morphMany(PhoneNumber::class, 'owner');
    }
}
