<?php

namespace Tests\Feature\Auth\PublicApi;

use App\Enums\AccountStatus;
use App\Enums\OtpPurpose;
use App\Enums\OtpStatus;
use App\Enums\PhoneStatus;
use App\Models\EmailVerificationOtp;
use App\Models\User;
use App\Notifications\EmailOtpNotification;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Nnjeim\World\Models\Country;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    private string $url = '/api/public/auth/register';

    private Country $egypt;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->egypt = $this->createCountry('EG', 'Egypt', '20');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Mona Saleh',
            'email' => 'mona@example.com',
            'phone' => '+201001234567',
            'country_id' => $this->egypt->id,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ], $overrides);
    }

    public function test_student_can_register(): void
    {
        $this->postJson($this->url, $this->payload())
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.email', 'mona@example.com');

        $this->assertDatabaseHas('users', [
            'email' => 'mona@example.com',
            'name' => 'Mona Saleh',
        ]);
    }

    public function test_new_account_is_pending_and_unverified(): void
    {
        $this->postJson($this->url, $this->payload())->assertCreated();

        $user = User::where('email', 'mona@example.com')->sole();

        $this->assertSame(AccountStatus::Pending, $user->status);
        $this->assertNull($user->email_verified_at);
        $this->assertFalse($user->hasVerifiedEmail());
    }

    public function test_phone_is_stored_through_the_phone_numbers_architecture(): void
    {
        $this->postJson($this->url, $this->payload())->assertCreated();

        $user = User::where('email', 'mona@example.com')->sole();
        $phone = $user->phoneNumbers()->sole();

        $this->assertSame('+201001234567', $phone->e164_number);
        $this->assertSame('EG', $phone->country_iso2);
        $this->assertTrue($phone->is_primary);

        $this->assertFalse(
            Schema::hasColumn('users', 'phone'),
            'The users table must not gain a phone column.',
        );
    }

    public function test_selected_country_is_stored_in_users_country_id(): void
    {
        $other = $this->createCountry('SA', 'Saudi Arabia', '966');

        $this->postJson($this->url, $this->payload())->assertCreated();

        $user = User::where('email', 'mona@example.com')->sole();

        $this->assertSame($this->egypt->id, $user->country_id);
        $this->assertNotSame($other->id, $user->country_id);
        $this->assertSame('EG', $user->country->iso2);
    }

    public function test_registration_issues_a_pending_email_otp(): void
    {
        $this->postJson($this->url, $this->payload())->assertCreated();

        $user = User::where('email', 'mona@example.com')->sole();
        $otp = $user->emailVerificationOtps()->sole();

        $this->assertSame(OtpPurpose::EmailVerification, $otp->purpose);
        $this->assertSame(OtpStatus::Pending, $otp->status);
        $this->assertSame(0, $otp->attempts);
        $this->assertSame(5, $otp->max_attempts);
        $this->assertTrue($otp->expires_at->between(now()->addMinutes(9), now()->addMinutes(11)));
        $this->assertNotNull($otp->ip_address);

        Notification::assertSentTo($user, EmailOtpNotification::class);
    }

    public function test_otp_code_is_never_persisted_or_returned_in_plaintext(): void
    {
        $response = $this->postJson($this->url, $this->payload())->assertCreated();

        $otp = EmailVerificationOtp::sole();

        $this->assertStringStartsWith('$2y$', $otp->code_hash);
        $this->assertArrayNotHasKey('code', $response->json('data'));
        $this->assertArrayNotHasKey('code_hash', $otp->toArray());
    }

    public function test_registration_does_not_send_the_signed_verification_link(): void
    {
        $this->postJson($this->url, $this->payload())->assertCreated();

        $user = User::where('email', 'mona@example.com')->sole();

        Notification::assertNotSentTo($user, VerifyEmail::class);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'mona@example.com']);

        $this->postJson($this->url, $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->assertSame(0, EmailVerificationOtp::count());
    }

    public function test_duplicate_phone_is_rejected_and_rolls_back_the_account(): void
    {
        $existing = User::factory()->create(['email' => 'other@example.com']);
        $existing->phoneNumbers()->create([
            'owner_type' => $existing->getMorphClass(),
            'owner_id' => $existing->getKey(),
            'country_iso2' => 'EG',
            'country_code' => '+20',
            'national_number' => '1001234567',
            'e164_number' => '+201001234567',
            'is_primary' => true,
            'status' => PhoneStatus::Pending,
        ]);

        $this->postJson($this->url, $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'PHONE_ALREADY_REGISTERED');

        $this->assertDatabaseMissing('users', ['email' => 'mona@example.com']);
        $this->assertSame(0, EmailVerificationOtp::count());
    }

    public function test_phone_is_parsed_with_the_selected_countrys_iso2(): void
    {
        // A different configured fallback proves the ISO2 handed to the phone
        // parser is resolved from country_id, not from config('phone.default_country').
        config(['phone.default_country' => 'US']);

        $britain = $this->createCountry('GB', 'United Kingdom', '44');

        $this->postJson($this->url, $this->payload([
            'phone' => '02079460958',
            'country_id' => $britain->id,
        ]))->assertCreated();

        $phone = User::where('email', 'mona@example.com')->sole()->phoneNumbers()->sole();

        $this->assertSame('+442079460958', $phone->e164_number);
        $this->assertSame('GB', $phone->country_iso2);
        $this->assertSame('+44', $phone->country_code);
    }

    public function test_phone_must_be_valid_for_the_selected_country(): void
    {
        config(['phone.default_country' => 'GB']);

        // A GB national number submitted while Egypt is the selected country must
        // not be rescued by the configured fallback.
        $this->postJson($this->url, $this->payload(['phone' => '02079460958']))
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_PHONE');

        $this->assertDatabaseMissing('users', ['email' => 'mona@example.com']);
    }

    public function test_students_may_register_with_a_non_mena_phone_country(): void
    {
        config(['phone.allowed_countries' => ['EG', 'SA', 'AE']]);

        $britain = $this->createCountry('GB', 'United Kingdom', '44');

        $this->postJson($this->url, $this->payload([
            'phone' => '+442079460958',
            'country_id' => $britain->id,
        ]))->assertCreated();

        $user = User::where('email', 'mona@example.com')->sole();

        $this->assertSame($britain->id, $user->country_id);
        $this->assertSame('GB', $user->phoneNumbers()->sole()->country_iso2);
    }

    public function test_unknown_country_id_is_rejected(): void
    {
        $this->postJson($this->url, $this->payload(['country_id' => 999999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['country_id'])
            ->assertJsonPath('errors.country_id.0', 'The selected country is not supported.');

        $this->assertDatabaseMissing('users', ['email' => 'mona@example.com']);
    }

    public function test_missing_country_id_is_rejected(): void
    {
        $payload = $this->payload();
        unset($payload['country_id']);

        $this->postJson($this->url, $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['country_id']);

        $this->assertDatabaseMissing('users', ['email' => 'mona@example.com']);
    }

    public function test_non_integer_country_id_is_rejected(): void
    {
        foreach (['EG', 'not-a-number', 1.5, []] as $value) {
            $this->postJson($this->url, $this->payload(['country_id' => $value]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['country_id']);
        }

        $this->assertDatabaseMissing('users', ['email' => 'mona@example.com']);
    }

    public function test_iso_country_codes_are_no_longer_accepted(): void
    {
        // The public contract is country_id only; `country` is not an alias.
        $payload = $this->payload();
        unset($payload['country_id']);

        $this->postJson($this->url, $payload + ['country' => 'EG'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['country_id']);

        $this->assertDatabaseMissing('users', ['email' => 'mona@example.com']);
    }

    public function test_invalid_phone_is_rejected(): void
    {
        $this->postJson($this->url, $this->payload(['phone' => '12']))
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_PHONE');

        $this->assertDatabaseMissing('users', ['email' => 'mona@example.com']);
    }

    public function test_password_must_be_confirmed(): void
    {
        $this->postJson($this->url, $this->payload(['password_confirmation' => 'different']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_short_password_is_rejected(): void
    {
        $this->postJson($this->url, $this->payload([
            'password' => 'short',
            'password_confirmation' => 'short',
        ]))->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    public function test_required_fields_are_validated(): void
    {
        $this->postJson($this->url, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['full_name', 'email', 'phone', 'country_id', 'password']);
    }
}
