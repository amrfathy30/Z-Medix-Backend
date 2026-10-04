<?php

namespace App\Http\Controllers\Api\Public\Auth;

use App\Enums\AccountStatus;
use App\Exceptions\Phone\InvalidPhoneException;
use App\Exceptions\Phone\MaxPhoneNumbersReachedException;
use App\Exceptions\Phone\PhoneAlreadyExistsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Public\Auth\RegisterRequest;
use App\Models\User;
use App\Services\Auth\EmailOtpService;
use App\Services\Phone\PhoneNumberService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Nnjeim\World\Models\Country;

/**
 * Registers a student account in the pending, unverified state and sends the
 * email verification code that activates it.
 */
class RegisterController extends Controller
{
    use ApiResponse;

    public function __invoke(
        RegisterRequest $request,
        PhoneNumberService $phoneNumbers,
        EmailOtpService $emailOtp,
    ): JsonResponse {
        $countryId = $request->integer('country_id');

        // The request carries the country id; the phone parser needs that country's
        // ISO2 code, which is resolved here rather than being asked of the client.
        $countryIso2 = (string) Country::query()->whereKey($countryId)->value('iso2');

        try {
            $user = DB::transaction(function () use ($request, $phoneNumbers, $countryId, $countryIso2): User {
                $user = new User([
                    'name' => $request->string('full_name')->value(),
                    'email' => $request->string('email')->value(),
                    'country_id' => $countryId,
                    'password' => $request->string('password')->value(),
                ]);

                // Not mass assignable: the account must start pending and unverified
                // regardless of request payload.
                $user->status = AccountStatus::Pending;
                $user->save();

                $phoneNumbers->addPhone($user, [
                    'phone' => $request->string('phone')->value(),
                    'country' => $countryIso2,
                ], allowedCountries: $this->allowedPhoneCountries());

                return $user;
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

        $emailOtp->sendForRegistration($user, metadata: [
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return $this->successResponse([
            'email' => $user->email,
        ], 'Registration successful. We have sent a verification code to your email address.', Response::HTTP_CREATED);
    }

    /**
     * Students may register from any parseable country; the MENA allow-list in
     * config/phone.php governs the other phone flows only.
     *
     * @return list<string>
     */
    private function allowedPhoneCountries(): array
    {
        /** @var list<string> $countries */
        $countries = config('auth_features.registration.allowed_phone_countries', []);

        return $countries;
    }
}
