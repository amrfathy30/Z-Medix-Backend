<?php

namespace Tests\Feature\Auth\PublicApi;

use App\Contracts\PhoneVerificationProviderInterface;
use App\Enums\OtpStatus;
use App\Enums\PhoneStatus;
use App\Models\PhoneNumber;
use App\Models\User;
use App\Services\Phone\Providers\LogPhoneVerificationProvider;
use App\Services\Phone\Providers\TwilioVerifyProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Phone\FakePhoneVerificationProvider;
use Tests\TestCase;

class ChangePhoneTestOtpCodeTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/public/auth/change-phone';

    private const CODE = '999999';

    private const TARGET = '+201112345678';

    private User $user;

    private PhoneNumber $phone;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'phone.otp_enabled' => true,
            'phone.default_provider' => 'log',
            'phone.otp_test_code' => self::CODE,
            'phone.otp_test_code_environments' => ['local', 'testing'],
        ]);
        Log::spy();
        $this->assertInstanceOf(LogPhoneVerificationProvider::class, $this->app->make(PhoneVerificationProviderInterface::class));
        $this->user = User::factory()->create();
        $this->phone = PhoneNumber::factory()->forOwner($this->user)->primary()->verified()->create();
        Sanctum::actingAs($this->user);
    }

    private function start(): void
    {
        $this->postJson(self::URL, ['phone' => self::TARGET, 'channel' => 'sms'])
            ->assertOk()->assertJsonPath('data.phone', self::TARGET)->assertJsonPath('data.phone_verified', false);
    }

    public function test_configured_log_provider_supports_send_resend_and_test_code_verification(): void
    {
        $verifiedAt = $this->phone->verified_at;
        $this->start();
        $firstOtp = $this->phone->otps()->sole();
        $this->assertStringStartsWith('log-', $firstOtp->code_hash);
        $this->assertSame(self::TARGET, $firstOtp->target_phone);
        $this->assertSame('+201012345678', $this->phone->fresh()->e164_number);
        $this->assertEquals($verifiedAt, $this->phone->fresh()->verified_at);

        $this->postJson(self::URL.'/resend')->assertStatus(429)->assertJsonPath('code', 'PHONE_OTP_RESEND_COOLDOWN');
        $this->travel(61)->seconds();
        $response = $this->postJson(self::URL.'/resend', ['phone' => '+201212345678'])
            ->assertOk()->assertJsonPath('data.phone', self::TARGET);
        $this->assertStringNotContainsString(self::CODE, $response->getContent());
        $this->assertSame('+201012345678', $this->phone->fresh()->e164_number);
        $this->assertSame(OtpStatus::Expired, $firstOtp->fresh()->status);

        $this->postJson(self::URL.'/verify', ['code' => self::CODE])->assertExactJson([
            'success' => true,
            'message' => 'Phone number changed successfully.',
            'data' => ['phone' => self::TARGET, 'phone_verified' => true],
        ]);
        $this->assertSame(self::TARGET, $this->phone->fresh()->e164_number);
        $this->assertSame(PhoneStatus::Verified, $this->phone->fresh()->status);
        $this->assertNotNull($this->phone->fresh()->verified_at);
        $this->assertTrue($this->phone->fresh()->is_primary);
        $consumed = $this->phone->otps()->latest('id')->first();
        $this->assertSame(OtpStatus::Verified, $consumed->status);
        $this->assertSame(1, $consumed->attempts);
        $this->assertNull($consumed->target_phone);
        $this->postJson(self::URL.'/verify', ['code' => self::CODE])->assertStatus(422)->assertJsonPath('code', 'PHONE_OTP_NOT_FOUND');
        $this->postJson(self::URL.'/resend')->assertStatus(422)->assertJsonPath('code', 'PHONE_OTP_NOT_FOUND');
        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
            $this->assertStringNotContainsString(self::CODE, $message.json_encode($context));

            return $context['to'] === self::TARGET && $context['purpose'] === 'phone_change';
        })->twice();
    }

    public function test_incorrect_test_code_is_rejected_and_attempts_remain_enforced(): void
    {
        config(['phone.otp_max_attempts' => 1]);
        $this->start();
        $this->postJson(self::URL.'/verify', ['code' => '000000'])->assertStatus(422)->assertJsonPath('code', 'PHONE_OTP_INVALID');
        $this->postJson(self::URL.'/verify', ['code' => self::CODE])->assertStatus(429)->assertJsonPath('code', 'PHONE_OTP_MAX_ATTEMPTS_EXCEEDED');
        $this->assertSame('+201012345678', $this->phone->fresh()->e164_number);
    }

    public function test_test_code_requires_an_unexpired_pending_change(): void
    {
        $this->postJson(self::URL.'/verify', ['code' => self::CODE])->assertStatus(422)->assertJsonPath('code', 'PHONE_OTP_NOT_FOUND');
        $this->postJson(self::URL.'/resend')->assertStatus(422)->assertJsonPath('code', 'PHONE_OTP_NOT_FOUND');
        $this->start();
        $this->travel(11)->minutes();
        $this->postJson(self::URL.'/verify', ['code' => self::CODE])->assertStatus(422)->assertJsonPath('code', 'PHONE_OTP_EXPIRED');
        $this->postJson(self::URL.'/resend')->assertStatus(422)->assertJsonPath('code', 'PHONE_OTP_EXPIRED');
        $this->assertSame('+201012345678', $this->phone->fresh()->e164_number);
    }

    public function test_test_code_cannot_act_on_another_users_pending_change(): void
    {
        $this->start();
        $other = User::factory()->create();
        $otherPhone = PhoneNumber::factory()->forOwner($other)->primary()->verified()->create(['e164_number' => '+201212345678']);
        Sanctum::actingAs($other);
        $this->postJson(self::URL.'/verify', ['code' => self::CODE, 'phone_id' => $this->phone->id])->assertStatus(422)->assertJsonPath('code', 'PHONE_OTP_NOT_FOUND');
        $this->postJson(self::URL.'/resend', ['phone' => self::TARGET])->assertStatus(422)->assertJsonPath('code', 'PHONE_OTP_NOT_FOUND');
        $this->assertSame('+201012345678', $this->phone->fresh()->e164_number);
        $this->assertSame('+201212345678', $otherPhone->fresh()->e164_number);
        $this->assertSame(OtpStatus::Pending, $this->phone->otps()->sole()->status);
    }

    public function test_test_mode_preserves_the_hourly_send_limit(): void
    {
        config(['phone.otp_max_sends_per_hour' => 1]);
        $this->start();
        $this->travel(61)->seconds();
        $this->postJson(self::URL.'/resend')->assertStatus(429)->assertJsonPath('code', 'PHONE_OTP_SEND_LIMIT_REACHED');
    }

    public function test_disabled_otp_blocks_test_mode_without_changing_the_phone(): void
    {
        $this->start();
        config(['phone.otp_enabled' => false]);
        foreach (['' => ['phone' => self::TARGET], '/resend' => [], '/verify' => ['code' => self::CODE]] as $suffix => $body) {
            $this->postJson(self::URL.$suffix, $body)->assertStatus(503)->assertJsonPath('code', 'PHONE_OTP_DISABLED');
        }
        $this->assertSame('+201012345678', $this->phone->fresh()->e164_number);
        $this->assertSame(OtpStatus::Pending, $this->phone->otps()->sole()->status);
    }

    /** @return array<string, array{list<string>}> */
    public static function productionAllowLists(): array
    {
        return [
            'explicit production' => [['production', 'testing']],
            'leaked local configuration' => [['local', 'testing']],
            'all environments wildcard' => [['*']],
            'production wildcard' => [['prod*']],
        ];
    }

    #[DataProvider('productionAllowLists')]
    public function test_production_refuses_the_fixed_code_even_with_misconfigured_allow_lists(array $environments): void
    {
        config(['phone.otp_test_code_environments' => $environments, 'app.debug' => true]);
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->start();
        $this->postJson(self::URL.'/verify', ['code' => self::CODE])->assertStatus(422)->assertJsonPath('code', 'PHONE_OTP_INVALID');
        $this->assertSame('+201012345678', $this->phone->fresh()->e164_number);
    }

    public function test_the_code_is_configurable_and_local_must_be_explicitly_allowed(): void
    {
        config(['phone.otp_test_code' => '888888']);
        $this->app->detectEnvironment(fn (): string => 'local');
        $this->start();
        $this->postJson(self::URL.'/verify', ['code' => self::CODE])->assertStatus(422);
        $this->postJson(self::URL.'/verify', ['code' => '888888'])->assertOk();
    }

    public function test_code_alone_and_debug_mode_do_not_enable_the_bypass(): void
    {
        config(['phone.otp_test_code_environments' => [], 'app.debug' => true]);
        $this->start();
        $this->postJson(self::URL.'/verify', ['code' => self::CODE])->assertStatus(422)->assertJsonPath('code', 'PHONE_OTP_INVALID');
    }

    public function test_log_provider_alone_does_not_enable_the_bypass(): void
    {
        config(['phone.otp_test_code' => null, 'app.debug' => true]);
        $this->start();
        $this->postJson(self::URL.'/verify', ['code' => self::CODE])->assertStatus(422)->assertJsonPath('code', 'PHONE_OTP_INVALID');
    }

    public function test_disabled_test_mode_keeps_the_twilio_provider_and_normal_verification_path(): void
    {
        config(['phone.default_provider' => 'twilio_verify', 'phone.otp_test_code' => null, 'app.debug' => true]);
        $this->assertInstanceOf(TwilioVerifyProvider::class, $this->app->make(PhoneVerificationProviderInterface::class));
        $provider = new FakePhoneVerificationProvider('123456');
        $this->app->instance(PhoneVerificationProviderInterface::class, $provider);
        $this->start();
        $this->postJson(self::URL.'/verify', ['code' => self::CODE])->assertStatus(422)->assertJsonPath('code', 'PHONE_OTP_INVALID');
        $this->postJson(self::URL.'/verify', ['code' => '123456'])->assertOk();
        $this->assertSame([self::TARGET], $provider->numbersSentTo());
        $this->assertSame([self::CODE, '123456'], array_column($provider->checked, 'code'));
        $this->assertSame([self::TARGET, self::TARGET], array_column($provider->checked, 'e164'));
    }

    public function test_enabling_a_fixed_code_does_not_automatically_replace_the_delivery_provider(): void
    {
        config(['phone.default_provider' => 'twilio_verify']);
        $this->assertInstanceOf(TwilioVerifyProvider::class, $this->app->make(PhoneVerificationProviderInterface::class));
        $provider = new FakePhoneVerificationProvider;
        $provider->shouldFailDelivery = true;
        $this->app->instance(PhoneVerificationProviderInterface::class, $provider);
        $this->postJson(self::URL, ['phone' => self::TARGET])->assertStatus(503)->assertJsonPath('code', 'PHONE_OTP_SEND_FAILED');
        $this->assertSame(0, $this->phone->otps()->count());
        $this->assertSame('+201012345678', $this->phone->fresh()->e164_number);
    }
}
