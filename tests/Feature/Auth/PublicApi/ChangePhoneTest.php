<?php

namespace Tests\Feature\Auth\PublicApi;

use App\Contracts\PhoneVerificationProviderInterface;
use App\Enums\OtpPurpose;
use App\Enums\OtpStatus;
use App\Enums\PhoneStatus;
use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Phone\FakePhoneVerificationProvider;
use Tests\TestCase;

class ChangePhoneTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/public/auth/change-phone';

    private const NEW_PHONE = '+201112345678';

    private FakePhoneVerificationProvider $provider;

    private User $user;

    private PhoneNumber $phone;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['phone.otp_enabled' => true, 'phone.otp_test_code' => null]);
        $this->provider = new FakePhoneVerificationProvider;
        $this->app->instance(PhoneVerificationProviderInterface::class, $this->provider);
        $this->user = User::factory()->create();
        $this->phone = PhoneNumber::factory()->forOwner($this->user)->primary()->verified()->create();
    }

    private function start(): void
    {
        Sanctum::actingAs($this->user);
        $this->postJson(self::URL, ['phone' => self::NEW_PHONE])
            ->assertOk()
            ->assertJsonPath('data.phone', self::NEW_PHONE)
            ->assertJsonPath('data.phone_verified', false)
            ->assertJsonStructure(['success', 'message', 'data' => ['phone', 'phone_verified', 'expires_at']])
            ->assertJsonMissingPath('data.code')
            ->assertJsonMissingPath('data.code_hash')
            ->assertJsonMissingPath('data.provider_reference');
    }

    public function test_send_stages_target_without_changing_current_phone_and_verify_commits_once(): void
    {
        $oldVerifiedAt = $this->phone->verified_at;
        $this->start();
        $this->assertSame([self::NEW_PHONE], $this->provider->numbersSentTo());
        $this->assertSame('phone_change', $this->provider->sent[0]['purpose']);
        $this->assertSame('+201012345678', $this->phone->fresh()->e164_number);
        $this->assertEquals($oldVerifiedAt, $this->phone->fresh()->verified_at);
        $otp = $this->phone->otps()->sole();
        $this->assertSame(self::NEW_PHONE, $otp->target_phone);

        $this->postJson(self::URL.'/verify', ['code' => '123456'])
            ->assertExactJson([
                'success' => true,
                'message' => 'Phone number changed successfully.',
                'data' => ['phone' => self::NEW_PHONE, 'phone_verified' => true],
            ]);

        $this->assertSame(self::NEW_PHONE, $this->provider->checked[0]['e164']);
        $this->assertSame(self::NEW_PHONE, $this->phone->fresh()->e164_number);
        $this->assertSame('1112345678', $this->phone->fresh()->national_number);
        $this->assertSame(PhoneStatus::Verified, $this->phone->fresh()->status);
        $this->assertTrue($this->phone->fresh()->is_primary);
        $this->assertSame(1, $this->user->phoneNumbers()->count());
        $this->assertSame(OtpStatus::Verified, $otp->fresh()->status);
        $this->assertNull($otp->fresh()->target_phone);
        $this->postJson(self::URL.'/verify', ['code' => '123456'])->assertStatus(422)->assertJsonPath('code', 'PHONE_OTP_NOT_FOUND');
        $this->postJson(self::URL.'/resend')->assertStatus(422)->assertJsonPath('code', 'PHONE_OTP_NOT_FOUND');
        $this->assertCount(1, $this->provider->checked);
    }

    public function test_resend_uses_only_pending_target_and_enforces_cooldown(): void
    {
        $this->start();
        $this->postJson(self::URL.'/resend')->assertStatus(429)->assertJsonPath('code', 'PHONE_OTP_RESEND_COOLDOWN');
        $this->travel(61)->seconds();
        $this->postJson(self::URL.'/resend', ['phone' => '+201212345678'])
            ->assertOk()->assertJsonPath('data.phone', self::NEW_PHONE);
        $this->assertSame([self::NEW_PHONE, self::NEW_PHONE], $this->provider->numbersSentTo());
        $this->assertSame('+201012345678', $this->phone->fresh()->e164_number);
        $this->assertSame(OtpStatus::Expired, $this->phone->otps()->oldest('id')->first()->status);
        $this->postJson(self::URL.'/verify', ['code' => '123456'])->assertOk();
        $this->postJson(self::URL.'/resend')->assertStatus(422)->assertJsonPath('code', 'PHONE_OTP_NOT_FOUND');
        $this->postJson(self::URL.'/verify', ['code' => '123456'])->assertStatus(422)->assertJsonPath('code', 'PHONE_OTP_NOT_FOUND');
    }

    public function test_invalid_codes_are_counted_and_burned(): void
    {
        config(['phone.otp_max_attempts' => 2]);
        $this->start();
        $this->postJson(self::URL.'/verify', ['code' => '000000'])->assertStatus(422)->assertJsonPath('code', 'PHONE_OTP_INVALID');
        $this->postJson(self::URL.'/verify', ['code' => '000000'])->assertStatus(422);
        $this->postJson(self::URL.'/verify', ['code' => '123456'])->assertStatus(429)->assertJsonPath('code', 'PHONE_OTP_MAX_ATTEMPTS_EXCEEDED');
        $this->assertSame(2, $this->phone->otps()->sole()->attempts);
        $this->assertSame('+201012345678', $this->phone->fresh()->e164_number);
    }

    public function test_expired_attempt_cannot_be_verified_or_resent(): void
    {
        $this->start();
        $this->travel(11)->minutes();
        $this->postJson(self::URL.'/verify', ['code' => '123456'])->assertStatus(422)->assertJsonPath('code', 'PHONE_OTP_EXPIRED');
        $this->postJson(self::URL.'/resend')->assertStatus(422)->assertJsonPath('code', 'PHONE_OTP_EXPIRED');
        $this->assertSame('+201012345678', $this->phone->fresh()->e164_number);
        $this->assertCount(0, $this->provider->checked);
    }

    public function test_no_pending_request_is_rejected(): void
    {
        Sanctum::actingAs($this->user);
        $this->postJson(self::URL.'/resend')->assertStatus(422)->assertJsonPath('code', 'PHONE_OTP_NOT_FOUND');
        $this->postJson(self::URL.'/verify', ['code' => '123456'])->assertStatus(422)->assertJsonPath('code', 'PHONE_OTP_NOT_FOUND');
    }

    public function test_another_users_attempt_cannot_be_used(): void
    {
        $this->start();
        $other = User::factory()->create();
        PhoneNumber::factory()->forOwner($other)->primary()->create(['e164_number' => '+201212345678']);
        Sanctum::actingAs($other);
        $this->postJson(self::URL.'/verify', ['code' => '123456', 'phone_id' => $this->phone->id])->assertStatus(422)->assertJsonPath('code', 'PHONE_OTP_NOT_FOUND');
        $this->postJson(self::URL.'/resend', ['phone' => self::NEW_PHONE])->assertStatus(422);
        $this->assertCount(0, $this->provider->checked);
    }

    public function test_duplicate_ownership_is_rejected_at_send_and_rechecked_at_verify(): void
    {
        $other = PhoneNumber::factory()->forOwner(User::factory()->create())->create(['e164_number' => self::NEW_PHONE]);
        Sanctum::actingAs($this->user);
        $this->postJson(self::URL, ['phone' => self::NEW_PHONE])->assertStatus(422)->assertJsonPath('code', 'PHONE_ALREADY_REGISTERED');
        $this->assertSame(0, $this->provider->sendCount());
        $other->delete();
        $this->start();
        $other->restore();
        $this->postJson(self::URL.'/verify', ['code' => '123456'])->assertStatus(422)->assertJsonPath('code', 'PHONE_ALREADY_REGISTERED');
        $this->assertSame('+201012345678', $this->phone->fresh()->e164_number);
        $this->assertSame(OtpStatus::Pending, $this->phone->otps()->sole()->status);
    }

    public function test_new_request_supersedes_previous_target(): void
    {
        $this->start();
        $old = $this->phone->otps()->sole();
        $this->postJson(self::URL, ['phone' => '+201212345678'])->assertOk();
        $this->assertSame(OtpStatus::Expired, $old->fresh()->status);
        $this->postJson(self::URL.'/verify', ['code' => '123456'])->assertOk()->assertJsonPath('data.phone', '+201212345678');
        $this->assertSame('+201212345678', $this->provider->checked[0]['e164']);
    }

    public function test_failed_delivery_preserves_previous_pending_attempt(): void
    {
        $this->start();
        $pending = $this->phone->otps()->sole();
        $this->provider->shouldFailDelivery = true;
        $this->postJson(self::URL, ['phone' => '+201212345678'])->assertStatus(503)->assertJsonPath('code', 'PHONE_OTP_SEND_FAILED');
        $this->assertSame(OtpStatus::Pending, $pending->fresh()->status);
        $this->assertSame(self::NEW_PHONE, $pending->fresh()->target_phone);
        $this->assertSame('+201012345678', $this->phone->fresh()->e164_number);
    }

    public function test_invalid_current_verified_number_and_request_fields_are_rejected(): void
    {
        Sanctum::actingAs($this->user);
        $this->postJson(self::URL, ['phone' => 'not-a-phone'])->assertStatus(422)->assertJsonPath('code', 'INVALID_PHONE');
        $this->postJson(self::URL, ['phone' => '01012345678'])->assertStatus(422)->assertJsonPath('code', 'PHONE_ALREADY_VERIFIED');
        $this->postJson(self::URL)->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->postJson(self::URL, ['phone' => self::NEW_PHONE, 'channel' => 'call'])->assertUnprocessable()->assertJsonValidationErrors('channel');
        $this->postJson(self::URL.'/verify', ['code' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertSame(0, $this->provider->sendCount());
    }

    public function test_all_endpoints_require_authentication(): void
    {
        foreach (['', '/resend', '/verify'] as $suffix) {
            $this->postJson(self::URL.$suffix)->assertUnauthorized();
        }
        $this->assertSame(0, $this->provider->sendCount());
    }

    public function test_disabled_otp_never_bypasses_verification(): void
    {
        $this->start();
        config(['phone.otp_enabled' => false]);
        foreach (['' => ['phone' => '+201212345678'], '/resend' => [], '/verify' => ['code' => '123456']] as $suffix => $data) {
            $this->postJson(self::URL.$suffix, $data)->assertStatus(503)->assertJsonPath('code', 'PHONE_OTP_DISABLED');
        }
        $this->assertSame('+201012345678', $this->phone->fresh()->e164_number);
        $this->assertCount(0, $this->provider->checked);
    }

    public function test_normal_verification_cannot_consume_change_phone_otp(): void
    {
        $this->phone->update(['status' => PhoneStatus::Pending, 'verified_at' => null]);
        $this->start();
        $this->postJson('/api/public/auth/verify-phone', ['code' => '123456'])->assertStatus(422)->assertJsonPath('code', 'PHONE_OTP_NOT_FOUND');
        $this->postJson('/api/public/auth/verify-phone/send')->assertOk();
        $this->postJson('/api/public/auth/verify-phone', ['code' => '123456'])->assertOk();
        $this->assertSame('+201012345678', $this->phone->fresh()->e164_number);
        $this->assertSame(OtpStatus::Pending, $this->phone->otps()->where('purpose', OtpPurpose::PhoneChange)->sole()->status);
        $this->postJson(self::URL.'/verify', ['code' => '123456'])->assertOk();
    }

    public function test_profile_phone_change_invalidates_the_pending_change(): void
    {
        $this->start();
        $this->postJson('/api/public/profile', ['phone' => '+201212345678'])->assertOk();
        $this->postJson(self::URL.'/verify', ['code' => '123456'])
            ->assertStatus(422)->assertJsonPath('code', 'PHONE_OTP_EXPIRED');
        $this->assertSame('+201212345678', $this->phone->fresh()->e164_number);
        $this->assertSame(PhoneStatus::Pending, $this->phone->fresh()->status);
    }

    public function test_disabled_flag_also_blocks_normal_phone_verification(): void
    {
        $this->phone->update(['status' => PhoneStatus::Pending, 'verified_at' => null]);
        Sanctum::actingAs($this->user);
        config(['phone.otp_enabled' => false]);
        $this->postJson('/api/public/auth/verify-phone/send')->assertStatus(503)->assertJsonPath('code', 'PHONE_OTP_DISABLED');
        $this->postJson('/api/public/auth/verify-phone', ['code' => '123456'])->assertStatus(503)->assertJsonPath('code', 'PHONE_OTP_DISABLED');
        $this->assertSame(PhoneStatus::Pending, $this->phone->fresh()->status);
    }

    public function test_hourly_quota_and_missing_current_phone_are_enforced(): void
    {
        config(['phone.otp_max_sends_per_hour' => 1]);
        $this->start();
        $this->travel(61)->seconds();
        $this->postJson(self::URL.'/resend')->assertStatus(429)->assertJsonPath('code', 'PHONE_OTP_SEND_LIMIT_REACHED');
        $this->phone->delete();
        $this->postJson(self::URL, ['phone' => self::NEW_PHONE])->assertStatus(422)->assertJsonPath('code', 'PHONE_NOT_FOUND');
    }
}
