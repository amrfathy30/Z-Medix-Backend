<?php

namespace App\Http\Resources\Api;

use App\Enums\PhoneStatus;
use App\Models\PhoneNumber;
use App\Models\User;
use App\Services\Media\MediaUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserProfileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $phone = $this->resource->primaryPhoneNumber();

        return [
            'id' => $this->id,
            'name' => $this->name,
            // Canonical student-facing key; `name` is kept for the existing contract.
            'full_name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->hasVerifiedEmail(),
            'is_email_verified' => $this->hasVerifiedEmail(),
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'phone' => $phone?->e164_number,
            'phone_verified' => $this->phoneIsVerified($phone),
            'country' => $this->country === null ? null : [
                'id' => $this->country->id,
                'name' => $this->country->name,
            ],
            'institution' => $this->institution,
            'field_of_study' => $this->field_of_study,
            'profile_photo' => app(MediaUploadService::class)
                ->getUrl($this->resource, User::PROFILE_PHOTO_COLLECTION),
            //'type' => $this->type?->value,
            'status' => $this->status?->value,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Verification state of the very phone number reported in `phone`, read from
     * the existing phone_numbers status rather than any duplicated flag.
     */
    private function phoneIsVerified(?PhoneNumber $phone): bool
    {
        return $phone?->status === PhoneStatus::Verified;
    }
}
