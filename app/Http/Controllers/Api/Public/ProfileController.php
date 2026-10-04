<?php

namespace App\Http\Controllers\Api\Public;

use App\Exceptions\Phone\InvalidPhoneException;
use App\Exceptions\Phone\MaxPhoneNumbersReachedException;
use App\Exceptions\Phone\PhoneAlreadyExistsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Public\ProfileUpdateRequest;
use App\Http\Resources\Api\UserProfileResource;
use App\Models\User;
use App\Services\Media\MediaUploadService;
use App\Services\Phone\PhoneNumberService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Nnjeim\World\Models\Country;

class ProfileController extends Controller
{
    use ApiResponse;

    public function show(Request $request): JsonResponse
    {
        return $this->successResponse(new UserProfileResource($request->user()));
    }

    public function update(
        ProfileUpdateRequest $request,
        PhoneNumberService $phoneNumbers,
        MediaUploadService $media,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            DB::transaction(function () use ($request, $user, $phoneNumbers, $media): void {
                $attributes = $request->profileAttributes();

                if ($attributes !== []) {
                    $user->fill($attributes)->save();
                }

                if ($request->has('phone')) {
                    $this->syncPhone($user, $request->string('phone')->value(), $phoneNumbers);
                }

                if ($request->hasFile('profile_photo')) {
                    $media->replace(
                        $user,
                        $request->file('profile_photo'),
                        User::PROFILE_PHOTO_COLLECTION,
                    );
                }
            });
        } catch (PhoneAlreadyExistsException|MaxPhoneNumbersReachedException $e) {
            return $this->errorResponse(
                $e->getMessage(),
                ['phone' => [$e->getMessage()]],
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'PHONE_ALREADY_REGISTERED',
            );
        } catch (InvalidPhoneException $e) {
            return $this->errorResponse(
                $e->getMessage(),
                ['phone' => [$e->getMessage()]],
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'INVALID_PHONE',
            );
        }

        return $this->successResponse(
            new UserProfileResource($user->fresh()),
            'Profile updated successfully.',
        );
    }

    /**
     * Store the submitted number through the existing phone_numbers architecture:
     * update the current primary number, or add one if the student has none.
     */
    private function syncPhone(User $user, string $phone, PhoneNumberService $phoneNumbers): void
    {
        $data = [
            'phone' => $phone,
            'country' => $this->phoneCountryIso2($user),
        ];

        $current = $user->primaryPhoneNumber();

        if ($current === null) {
            $phoneNumbers->addPhone($user, $data, allowedCountries: $this->allowedPhoneCountries());

            return;
        }

        $phoneNumbers->updatePhone($current, $data, allowedCountries: $this->allowedPhoneCountries());
    }

    /**
     * The student's selected country drives phone parsing, matching registration.
     */
    private function phoneCountryIso2(User $user): string
    {
        $iso2 = Country::query()->whereKey($user->country_id)->value('iso2');

        return is_string($iso2) && $iso2 !== ''
            ? $iso2
            : (string) config('phone.default_country');
    }

    /** @return list<string> */
    private function allowedPhoneCountries(): array
    {
        /** @var list<string> $countries */
        $countries = config('auth_features.registration.allowed_phone_countries', []);

        return $countries;
    }
}
