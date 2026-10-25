# Change phone API

All three endpoints require a Sanctum bearer token, even under `/public/auth/`.
Send these headers:

```http
Authorization: Bearer <access_token>
Accept: application/json
Content-Type: application/json
```

The account must already have a phone on record. The existing primary phone
(or the oldest phone when no primary flag exists) is replaced only after OTP
verification. A missing phone returns `PHONE_NOT_FOUND`; initial phone creation
continues through the existing registration/profile APIs.

## 1. Request a change

`POST /api/public/auth/change-phone`

```json
{
  "phone": "+201112345678",
  "channel": "sms"
}
```

`phone` is required, a string of at most 32 characters, parsed using the existing
phone library. National format is also accepted; the user's selected country
(or `phone.default_country`) supplies the region. The registration phone-country
allow-list applies. `channel` is optional, defaults to the configured channel,
and currently accepts `sms` only.

HTTP 200 (timestamp is illustrative; use the returned value):

```json
{
  "success": true,
  "message": "We have sent a verification code to your new phone number.",
  "data": {
    "phone": "+201112345678",
    "phone_verified": false,
    "expires_at": "2026-10-25T10:10:00+00:00"
  }
}
```

`data.phone` and `data.phone_verified` describe the pending target, not the
account's current phone. The current phone and its verification timestamp remain
unchanged. A successful new request expires the previous pending change. Failed
delivery rolls back replacement of the previous attempt. No OTP or provider
reference is returned.

## 2. Verify and apply the change

`POST /api/public/auth/change-phone/verify`

```json
{
  "code": "123456"
}
```

`code` is required and must be a string containing 4–10 digits, matching the
existing phone verification API convention. The example code is illustrative;
submit the code received by the user. No phone or attempt ID is required.

HTTP 200:

```json
{
  "success": true,
  "message": "Phone number changed successfully.",
  "data": {
    "phone": "+201112345678",
    "phone_verified": true
  }
}
```

The server resolves the latest phone-change attempt through the authenticated
user's phone relationship. Twilio checks the code against its pending target.
Ownership uniqueness is checked again before committing. The same phone row is
updated, retaining its relationship and primary flag, and marked verified. The
OTP is consumed and its pending target cleared. Replaying a completed attempt
is rejected, with no second provider check or phone update.

## 3. Resend

`POST /api/public/auth/change-phone/resend`

```json
{}
```

An optional `channel` field uses the same rules as the first endpoint. A submitted
`phone` is ignored; resend always resolves the pending target on the server.

HTTP 200:

```json
{
  "success": true,
  "message": "We have sent a verification code to your new phone number.",
  "data": {
    "phone": "+201112345678",
    "phone_verified": false,
    "expires_at": "2026-10-25T10:11:01+00:00"
  }
}
```

The current phone remains unchanged. Resend creates a new OTP record through the
existing service and expires the previous pending record. Twilio controls the
SMS code and may reuse it within its own validity window. Expired or exhausted
attempts require a fresh Change Phone request.

## Errors

Business errors use this envelope. Example: invalid code, HTTP 422:

```json
{
  "success": false,
  "message": "The verification code is invalid.",
  "errors": [],
  "code": "PHONE_OTP_INVALID"
}
```

Duplicate ownership, HTTP 422:

```json
{
  "success": false,
  "message": "The phone number '+201112345678' is already in use.",
  "errors": {
    "phone": ["The phone number '+201112345678' is already in use."]
  },
  "code": "PHONE_ALREADY_REGISTERED"
}
```

Form validation follows Laravel's existing response format. Missing phone,
HTTP 422:

```json
{
  "message": "The phone field is required.",
  "errors": {
    "phone": ["The phone field is required."]
  }
}
```

Missing/invalid authentication, HTTP 401:

```json
{
  "message": "Unauthenticated."
}
```

| HTTP | Code | Meaning / frontend action |
|---|---|---|
| 422 | `INVALID_PHONE` | Invalid or unsupported phone; correct the phone input. `errors.phone` contains the reason. |
| 422 | `PHONE_ALREADY_REGISTERED` | Target belongs to an existing phone under configured uniqueness rules, including another phone of this owner. |
| 422 | `PHONE_ALREADY_VERIFIED` | Target is the account's current verified phone; no SMS is sent. |
| 422 | `PHONE_NOT_FOUND` | Account has no current phone. |
| 422 | `PHONE_OTP_NOT_FOUND` | No pending change, another user's attempt, or consumed attempt; start a new change. |
| 422 | `PHONE_OTP_INVALID` | Incorrect code; retry while attempts remain. |
| 422 | `PHONE_OTP_EXPIRED` | Attempt expired or was invalidated; start a new change. |
| 429 | `PHONE_OTP_MAX_ATTEMPTS_EXCEEDED` | Attempt exhausted; start a new change. |
| 429 | `PHONE_OTP_RESEND_COOLDOWN` | Wait; message contains the remaining seconds. |
| 429 | `PHONE_OTP_SEND_LIMIT_REACHED` | Hourly delivery allowance exhausted; message contains the remaining seconds. |
| 503 | `PHONE_OTP_DISABLED` | Phone OTP is disabled; no change or SMS is permitted. |
| 503 | `PHONE_OTP_SEND_FAILED` | Provider unavailable or unable to check/send; retry later. |

Business errors without a field error use `errors: []`. Invalid numbers and
ownership conflicts use `errors.phone`. Required/type/length/channel validation
errors use the Laravel format above without a business `code`. The coarse route
throttle uses Laravel's HTTP 429 response (`message: "Too Many Attempts."`) and
rate-limit headers, without a business `code`.

The defaults are a 60-second cooldown, five sends per rolling hour, five
verification attempts per code, and a ten-minute local expiry. Configuration can
change these values. Delivery limits retain the existing number + purpose + IP
scope. The shared route throttle also allows ten requests per minute using the
existing Laravel route limiter conventions. Twilio applies its own limits and
expiry in addition to these local checks.

## Frontend sequence

1. Submit Change Phone with the desired number.
2. Keep displaying the existing account phone; show a separate pending target
   and code input using the response's normalized phone and expiry.
3. Optionally call resend after the cooldown, without submitting the phone again.
4. Submit the code to verify. Only after success replace the displayed account
   phone or refetch the profile.
5. For an expired, exhausted, or absent attempt, restart Change Phone.

## Configuration and compatibility

`PHONE_OTP_ENABLED` now maps to `phone.otp_enabled` and defaults to `true`,
preserving the previous enabled behavior. Setting it to `false` blocks sends
and code checks through the shared phone service; it never grants verification.
Existing already-verified normal-phone responses remain successful no-ops.
Existing non-production test-code guards remain in place.

The existing profile endpoint still allows immediate phone replacement and
resets verification. Such a replacement invalidates pending phone OTPs,
including phone-change attempts. It is not part of this staged OTP flow.

Global uniqueness remains governed by `phone.unique_globally`. The repository
intentionally removed its database unique constraint, so uniqueness remains an
application check, not a database-enforced invariant. Same-account change-phone
operations are serialized with database row locks; cross-account concurrency
against other phone-writing APIs is not proven by the SQLite feature tests.

## Deployment and verification

Apply `2026_10_25_100142_add_target_phone_to_phone_verification_otps_table.php`
before using these endpoints. It adds one nullable `target_phone` column to the
existing OTP table, which previously had no place to store a pending replacement.
No local, staging, or production migration was executed during this audit.

Focused verification:

```sh
php artisan test --compact tests/Feature/Auth/PublicApi/ChangePhoneTest.php tests/Feature/Auth/PublicApi/PhoneVerificationTest.php tests/Feature/Phone/PhoneTestOtpCodeTest.php
```

Result: 73 tests passed, 513 assertions. Tests use SQLite in memory and the fake
phone provider; no real SMS, full suite, or live Twilio checks were performed.
PHP formatting and `git diff --check` also passed.

## Files changed

- `app/Http/Controllers/Api/Public/Auth/ChangePhoneController.php`
- `app/Http/Requests/Api/Public/Auth/ChangePhoneRequest.php`
- `app/Services/Phone/ChangePhoneService.php`
- `app/Services/Phone/PhoneNumberService.php`
- `app/Services/Phone/Providers/TwilioVerifyProvider.php`
- `app/Models/PhoneVerificationOtp.php`
- `app/Exceptions/Phone/VerificationException.php`
- `config/phone.php`
- `routes/api/public.php`
- `database/migrations/2026_10_25_100142_add_target_phone_to_phone_verification_otps_table.php`
- `tests/Feature/Auth/PublicApi/ChangePhoneTest.php`
- `docs/change-phone-api.md`
