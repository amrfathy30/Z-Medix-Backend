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

    /**
     * The owner's current phone number: the one flagged primary, falling back to
     * the oldest on record.
     *
     * PhoneNumberService marks the first number primary on creation and keeps a
     * single primary through setPrimary()/deletePhone(), so the fallback only
     * matters for rows created outside that service.
     */
    public function primaryPhoneNumber(): ?PhoneNumber
    {
        return $this->phoneNumbers()
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->first();
    }
}
