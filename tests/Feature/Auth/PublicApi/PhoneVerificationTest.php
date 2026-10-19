<?php

namespace Tests\Feature\Auth\PublicApi;

use App\Contracts\PhoneVerificationProviderInterface;
use App\Enums\AccountStatus;
use App\Enums\OtpPurpose;
use App\Enums\OtpStatus;
use App\Enums\PhoneStatus;
use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Nnjeim\World\Models\Country;
use Tests\Support\Phone\FakePhoneVerificationProvider;
use Tests\TestCase;

class PhoneVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const SEND_URL = '/api/public/auth/verify-phone/send';

    private const VERIFY_URL = '/api/public/auth/verify-phone';

    private FakePhoneVerificationProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        // No real SMS is ever sent: the Twilio provider is replaced wholesale.
        $this->provider = new FakePhoneVerificationProvider;
        $this->app->instance(PhoneVerificationProviderInterface::class, $this->provider);
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ], $attributes));
    }

    private function country(string $iso2 = 'EG', string $name = 'Egypt', string $phoneCode = '20'): Country
    {
        return $this->createCountry($iso2, $name, $phoneCode);
    }

    private function phoneFor(
        User $user,
        PhoneStatus $status = PhoneStatus::Pending,
        string $e164 = '+201234567890',
    ): PhoneNumber {
        return $user->phoneNumbers()->create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'country_iso2' => 'EG',
            'country_code' => '+20',
            'national_number' => ltrim($e164, '+'),
            'e164_number' => $e164,
            'is_primary' => true,
            'status' => $status,
            'verified_at' => $status === PhoneStatus::Verified ? now() : null,
        ]);
    }

    // ─── Sending a code ──────────────────────────────────────────────────────

    public function test_authenticated_student_can_request_a_verification_code(): void
    {
        $user = $this->user();
        $phone = $this->phoneFor($user);
        Sanctum::actingAs($user);

        $this->postJson(self::SEND_URL)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.phone', '+201234567890')
            ->assertJsonPath('data.phone_verified', false)
            ->assertJsonStructure(['data' => ['phone', 'phone_verified', 'expires_at']]);

        $this->assertSame(1, $this->provider->sendCount());
        $this->assertSame(['+201234567890'], $this->provider->numbersSentTo());

        $this->assertDatabaseHas('phone_verification_otps', [
            'phone_number_id' => $phone->id,
            'purpose' => OtpPurpose::PhoneVerification->value,
            'status' => OtpStatus::Pending->value,
        ]);
    }

    public function test_send_records_the_request_ip_and_user_agent_on_the_otp(): void
    {
        $user = $this->user();
        $phone = $this->phoneFor($user);
        Sanctum::actingAs($user);

        $this->withHeader('User-Agent', 'ZmedixTest/1.0')
            ->postJson(self::SEND_URL)
            ->assertOk();

        $otp = $phone->otps()->sole();

        $this->assertNotNull($otp->ip_address);
        $this->assertSame('ZmedixTest/1.0', $otp->user_agent);
    }

    public function test_send_is_rejected_when_the_student_has_no_phone_on_record(): void
    {
        Sanctum::actingAs($this->user());

        $this->postJson(self::SEND_URL)
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'PHONE_NOT_FOUND');

        $this->assertSame(0, $this->provider->sendCount());
    }

    public function test_send_is_a_no_op_for_an_already_verified_phone(): void
    {
        $user = $this->user();
        $this->phoneFor($user, PhoneStatus::Verified);
        Sanctum::actingAs($user);

        $this->postJson(self::SEND_URL)
            ->assertOk()
            ->assertJsonPath('data.phone_verified', true);

        // Nothing billable happened.
        $this->assertSame(0, $this->provider->sendCount());
    }

    public function test_send_rejects_an_unsupported_channel(): void
    {
        $user = $this->user();
        $this->phoneFor($user);
        Sanctum::actingAs($user);

        $this->postJson(self::SEND_URL, ['channel' => 'whatsapp'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('channel');

        $this->assertSame(0, $this->provider->sendCount());
    }

    public function test_send_never_exposes_raw_provider_errors(): void
    {
        $user = $this->user();
        $this->phoneFor($user);
        Sanctum::actingAs($user);

        $this->provider->shouldFailDelivery = true;

        $response = $this->postJson(self::SEND_URL)
            ->assertStatus(503)
            ->assertJsonPath('code', 'PHONE_OTP_SEND_FAILED');

        $this->assertStringNotContainsStringIgnoringCase('twilio', $response->getContent());
    }

    public function test_a_failed_delivery_does_not_consume_the_hourly_allowance(): void
    {
        $user = $this->user();
        $this->phoneFor($user);
        Sanctum::actingAs($user);

        $this->provider->shouldFailDelivery = true;
        $this->postJson(self::SEND_URL)->assertStatus(503);

        // The cooldown was never recorded, so a retry is allowed immediately.
        $this->provider->shouldFailDelivery = false;
        $this->postJson(self::SEND_URL)->assertOk();

        $this->assertSame(1, $this->provider->sendCount());
    }

    // ─── Resend cooldown and hourly allowance ────────────────────────────────

    public function test_resend_is_blocked_by_the_cooldown(): void
    {
        $user = $this->user();
        $this->phoneFor($user);
        Sanctum::actingAs($user);

        $this->postJson(self::SEND_URL)->assertOk();

        $this->postJson(self::SEND_URL)
            ->assertStatus(429)
            ->assertJsonPath('code', 'PHONE_OTP_RESEND_COOLDOWN');

        $this->assertSame(1, $this->provider->sendCount());
    }

    public function test_resend_is_allowed_once_the_cooldown_has_elapsed(): void
    {
        $user = $this->user();
        $this->phoneFor($user);
        Sanctum::actingAs($user);

        $this->postJson(self::SEND_URL)->assertOk();

        $this->travel(config('phone.otp_resend_cooldown_seconds') + 1)->seconds();

        $this->postJson(self::SEND_URL)->assertOk();

        $this->assertSame(2, $this->provider->sendCount());
    }

    public function test_hourly_send_allowance_is_enforced(): void
    {
        $user = $this->user();
        $this->phoneFor($user);
        Sanctum::actingAs($user);

        $allowance = (int) config('phone.otp_max_sends_per_hour');
        $cooldown = (int) config('phone.otp_resend_cooldown_seconds');

        for ($i = 0; $i < $allowance; $i++) {
            $this->postJson(self::SEND_URL)->assertOk();
            $this->travel($cooldown + 1)->seconds();
        }

        $this->postJson(self::SEND_URL)
            ->assertStatus(429)
            ->assertJsonPath('code', 'PHONE_OTP_SEND_LIMIT_REACHED');

        $this->assertSame($allowance, $this->provider->sendCount());
    }

    public function test_issuing_a_new_code_expires_the_previous_pending_one(): void
    {
        $user = $this->user();
        $phone = $this->phoneFor($user);
        Sanctum::actingAs($user);

        $this->postJson(self::SEND_URL)->assertOk();
        $first = $phone->otps()->sole();

        $this->travel(config('phone.otp_resend_cooldown_seconds') + 1)->seconds();
        $this->postJson(self::SEND_URL)->assertOk();

        $this->assertSame(OtpStatus::Expired, $first->fresh()->status);
        $this->assertSame(1, $phone->otps()->where('status', OtpStatus::Pending)->count());
    }

    // ─── Verifying a code ────────────────────────────────────────────────────

    public function test_student_can_verify_their_phone_with_a_valid_code(): void
    {
        $user = $this->user();
        $phone = $this->phoneFor($user);
        Sanctum::actingAs($user);

        $this->postJson(self::SEND_URL)->assertOk();

        $this->postJson(self::VERIFY_URL, ['code' => '123456'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.phone_verified', true)
            ->assertJsonPath('data.user.phone_verified', true)
            ->assertJsonPath('data.user.phone', '+201234567890');

        $phone->refresh();

        $this->assertSame(PhoneStatus::Verified, $phone->status);
        $this->assertNotNull($phone->verified_at);
        $this->assertSame(OtpStatus::Verified, $phone->otps()->sole()->status);
    }

    public function test_an_invalid_code_is_rejected_and_leaves_the_phone_pending(): void
    {
        $user = $this->user();
        $phone = $this->phoneFor($user);
        Sanctum::actingAs($user);

        $this->postJson(self::SEND_URL)->assertOk();

        $this->postJson(self::VERIFY_URL, ['code' => '000000'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PHONE_OTP_INVALID');

        $this->assertSame(PhoneStatus::Pending, $phone->fresh()->status);
        $this->assertSame(1, $phone->otps()->sole()->attempts);
    }

    public function test_an_expired_code_is_rejected(): void
    {
        $user = $this->user();
        $phone = $this->phoneFor($user);
        Sanctum::actingAs($user);

        $this->postJson(self::SEND_URL)->assertOk();

        $this->travel(config('phone.otp_expiry_minutes') + 1)->minutes();

        $this->postJson(self::VERIFY_URL, ['code' => '123456'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PHONE_OTP_EXPIRED');

        $this->assertSame(PhoneStatus::Pending, $phone->fresh()->status);
        $this->assertSame(OtpStatus::Expired, $phone->otps()->sole()->status);
    }

    public function test_verification_attempts_are_capped(): void
    {
        $user = $this->user();
        $phone = $this->phoneFor($user);
        Sanctum::actingAs($user);

        $this->postJson(self::SEND_URL)->assertOk();

        $maxAttempts = (int) config('phone.otp_max_attempts');

        for ($i = 0; $i < $maxAttempts; $i++) {
            $this->postJson(self::VERIFY_URL, ['code' => '000000'])
                ->assertStatus(422)
                ->assertJsonPath('code', 'PHONE_OTP_INVALID');
        }

        // The correct code no longer helps once the allowance is spent.
        $this->postJson(self::VERIFY_URL, ['code' => '123456'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'PHONE_OTP_MAX_ATTEMPTS_EXCEEDED');

        $this->assertSame(PhoneStatus::Pending, $phone->fresh()->status);
    }

    public function test_verify_is_rejected_when_no_code_was_requested(): void
    {
        $user = $this->user();
        $this->phoneFor($user);
        Sanctum::actingAs($user);

        $this->postJson(self::VERIFY_URL, ['code' => '123456'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PHONE_OTP_NOT_FOUND');
    }

    public function test_verify_is_rejected_when_the_student_has_no_phone_on_record(): void
    {
        Sanctum::actingAs($this->user());

        $this->postJson(self::VERIFY_URL, ['code' => '123456'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PHONE_NOT_FOUND');
    }

    public function test_verify_is_a_no_op_for_an_already_verified_phone(): void
    {
        $user = $this->user();
        $this->phoneFor($user, PhoneStatus::Verified);
        Sanctum::actingAs($user);

        $this->postJson(self::VERIFY_URL, ['code' => '999999'])
            ->assertOk()
            ->assertJsonPath('data.phone_verified', true);

        $this->assertCount(0, $this->provider->checked);
    }

    public function test_verify_requires_a_numeric_code(): void
    {
        $user = $this->user();
        $this->phoneFor($user);
        Sanctum::actingAs($user);

        $this->postJson(self::VERIFY_URL, ['code' => 'abcdef'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->postJson(self::VERIFY_URL, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    // ─── Authentication and ownership ────────────────────────────────────────

    public function test_both_endpoints_reject_unauthenticated_requests(): void
    {
        $this->postJson(self::SEND_URL)->assertStatus(401);
        $this->postJson(self::VERIFY_URL, ['code' => '123456'])->assertStatus(401);

        $this->assertSame(0, $this->provider->sendCount());
    }

    public function test_the_code_is_always_sent_to_the_callers_own_primary_number(): void
    {
        $caller = $this->user();
        $this->phoneFor($caller, PhoneStatus::Pending, '+201234567890');

        $victim = $this->user();
        $this->phoneFor($victim, PhoneStatus::Pending, '+201119998888');

        Sanctum::actingAs($caller);

        // A client-supplied number is stripped before validation, so it can
        // never redirect the OTP.
        $this->postJson(self::SEND_URL, [
            'phone' => '+201119998888',
            'phone_id' => $victim->primaryPhoneNumber()->id,
        ])->assertOk();

        $this->assertSame(['+201234567890'], $this->provider->numbersSentTo());
    }

    public function test_verifying_cannot_mark_another_students_phone_as_verified(): void
    {
        $caller = $this->user();
        $this->phoneFor($caller, PhoneStatus::Pending, '+201234567890');

        $victim = $this->user();
        $victimPhone = $this->phoneFor($victim, PhoneStatus::Pending, '+201119998888');

        Sanctum::actingAs($caller);
        $this->postJson(self::SEND_URL)->assertOk();

        $this->postJson(self::VERIFY_URL, [
            'code' => '123456',
            'phone_id' => $victimPhone->id,
            'phone' => '+201119998888',
        ])->assertOk();

        $this->assertSame(PhoneStatus::Verified, $caller->primaryPhoneNumber()->status);
        $this->assertSame(PhoneStatus::Pending, $victimPhone->fresh()->status);
        $this->assertNull($victimPhone->fresh()->verified_at);
    }

    public function test_one_students_cooldown_does_not_block_another(): void
    {
        $first = $this->user();
        $this->phoneFor($first, PhoneStatus::Pending, '+201234567890');

        $second = $this->user();
        $this->phoneFor($second, PhoneStatus::Pending, '+201119998888');

        Sanctum::actingAs($first);
        $this->postJson(self::SEND_URL)->assertOk();
        $this->postJson(self::SEND_URL)->assertStatus(429);

        Sanctum::actingAs($second);
        $this->postJson(self::SEND_URL)->assertOk();

        $this->assertSame(['+201234567890', '+201119998888'], $this->provider->numbersSentTo());
    }

    // ─── phone_verified across the user-facing responses ─────────────────────

    public function test_profile_reports_phone_verified_after_verification(): void
    {
        $user = $this->user();
        $this->phoneFor($user);
        Sanctum::actingAs($user);

        $this->getJson('/api/public/profile')
            ->assertOk()
            ->assertJsonPath('data.phone_verified', false);

        $this->postJson(self::SEND_URL)->assertOk();
        $this->postJson(self::VERIFY_URL, ['code' => '123456'])->assertOk();

        $this->getJson('/api/public/profile')
            ->assertOk()
            ->assertJsonPath('data.phone_verified', true);
    }

    public function test_login_reports_the_current_phone_verification_state(): void
    {
        $user = $this->user(['password' => 'password']);
        $phone = $this->phoneFor($user, PhoneStatus::Verified);

        $this->postJson('/api/public/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->assertJsonPath('data.user.phone_verified', true);

        $phone->update(['status' => PhoneStatus::Pending, 'verified_at' => null]);

        $this->postJson('/api/public/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->assertJsonPath('data.user.phone_verified', false);
    }

    public function test_login_reports_phone_verified_false_when_no_phone_exists(): void
    {
        $user = $this->user(['password' => 'password']);

        $this->postJson('/api/public/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('data.user.phone', null)
            ->assertJsonPath('data.user.phone_verified', false);
    }

    // ─── Changing the number resets verification ─────────────────────────────

    public function test_changing_the_phone_number_resets_its_verification(): void
    {
        $user = $this->user(['country_id' => $this->country()->id]);
        $phone = $this->phoneFor($user, PhoneStatus::Verified);
        Sanctum::actingAs($user);

        $this->postJson('/api/public/profile', ['phone' => '+201119998888'])
            ->assertOk()
            ->assertJsonPath('data.phone', '+201119998888')
            ->assertJsonPath('data.phone_verified', false);

        $phone->refresh();

        $this->assertSame(PhoneStatus::Pending, $phone->status);
        $this->assertNull($phone->verified_at);
    }

    public function test_resubmitting_the_same_number_preserves_verification(): void
    {
        $user = $this->user(['country_id' => $this->country()->id]);
        $phone = $this->phoneFor($user, PhoneStatus::Verified);
        $verifiedAt = $phone->verified_at;
        Sanctum::actingAs($user);

        $this->postJson('/api/public/profile', ['phone' => '+201234567890'])
            ->assertOk()
            ->assertJsonPath('data.phone_verified', true);

        $phone->refresh();

        $this->assertSame(PhoneStatus::Verified, $phone->status);
        $this->assertTrue($verifiedAt->equalTo($phone->verified_at));
    }

    public function test_updating_other_profile_fields_preserves_phone_verification(): void
    {
        $user = $this->user(['country_id' => $this->country()->id]);
        $phone = $this->phoneFor($user, PhoneStatus::Verified);
        Sanctum::actingAs($user);

        $this->postJson('/api/public/profile', ['institution' => 'Cairo University'])
            ->assertOk()
            ->assertJsonPath('data.institution', 'Cairo University')
            ->assertJsonPath('data.phone_verified', true);

        $this->assertSame(PhoneStatus::Verified, $phone->fresh()->status);
    }

    public function test_a_code_issued_before_a_number_change_cannot_verify_the_new_number(): void
    {
        $user = $this->user(['country_id' => $this->country()->id]);
        $phone = $this->phoneFor($user);
        Sanctum::actingAs($user);

        $this->postJson(self::SEND_URL)->assertOk();
        $staleOtp = $phone->otps()->sole();

        $this->postJson('/api/public/profile', ['phone' => '+201119998888'])->assertOk();

        $this->assertSame(OtpStatus::Expired, $staleOtp->fresh()->status);

        $this->postJson(self::VERIFY_URL, ['code' => '123456'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PHONE_OTP_NOT_FOUND');

        $this->assertSame(PhoneStatus::Pending, $phone->fresh()->status);
    }

    public function test_a_new_code_verifies_the_changed_number(): void
    {
        $user = $this->user(['country_id' => $this->country()->id]);
        $phone = $this->phoneFor($user, PhoneStatus::Verified);
        Sanctum::actingAs($user);

        $this->postJson('/api/public/profile', ['phone' => '+201119998888'])->assertOk();

        $this->postJson(self::SEND_URL)->assertOk();
        $this->postJson(self::VERIFY_URL, ['code' => '123456'])->assertOk();

        $this->assertSame(['+201119998888'], $this->provider->numbersSentTo());
        $this->assertSame(PhoneStatus::Verified, $phone->fresh()->status);
    }

    // ─── Phone verification is optional ──────────────────────────────────────

    public function test_an_unverified_phone_does_not_block_login(): void
    {
        $user = $this->user(['password' => 'password']);
        $this->phoneFor($user, PhoneStatus::Pending);

        $this->postJson('/api/public/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->assertJsonStructure(['data' => ['token']]);
    }

    public function test_an_unverified_phone_does_not_block_platform_access(): void
    {
        $user = $this->user();
        $this->phoneFor($user, PhoneStatus::Pending);
        Sanctum::actingAs($user);

        $this->getJson('/api/public/profile')->assertOk();
        $this->getJson('/api/student/dashboard')->assertOk();
    }

    public function test_having_no_phone_at_all_does_not_block_platform_access(): void
    {
        Sanctum::actingAs($this->user());

        $this->getJson('/api/public/profile')->assertOk();
        $this->getJson('/api/student/dashboard')->assertOk();
    }

    public function test_rate_limiter_state_is_scoped_to_the_phone_number(): void
    {
        $user = $this->user();
        $this->phoneFor($user);
        Sanctum::actingAs($user);

        $this->postJson(self::SEND_URL)->assertOk();

        RateLimiter::clear('phone-otp-cooldown:'.sha1('+201234567890|'.OtpPurpose::PhoneVerification->value.'|127.0.0.1'));

        $this->postJson(self::SEND_URL)->assertOk();

        $this->assertSame(2, $this->provider->sendCount());
    }
}
