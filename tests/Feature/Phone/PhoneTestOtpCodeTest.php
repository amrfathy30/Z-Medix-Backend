<?php

namespace Tests\Feature\Phone;

use App\Contracts\PhoneVerificationProviderInterface;
use App\Enums\AccountStatus;
use App\Enums\OtpStatus;
use App\Enums\PhoneStatus;
use App\Models\User;
use App\Services\Phone\Providers\LogPhoneVerificationProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Phone\FakePhoneVerificationProvider;
use Tests\TestCase;

/**
 * The fixed test OTP (`999999` by convention) that lets a developer complete
 * phone verification locally without Twilio ever being contacted.
 *
 * The behaviour fails closed, so most of these tests are about the ways it must
 * NOT work: unset code, unset allow-list, wrong environment, and production.
 */
class PhoneTestOtpCodeTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_CODE = '999999';

    private const SEND_URL = '/api/public/auth/verify-phone/send';

    private const VERIFY_URL = '/api/public/auth/verify-phone';

    private FakePhoneVerificationProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        // The real code this fake accepts is deliberately NOT the test code, so
        // any success below can only have come from the test-code path.
        $this->provider = new FakePhoneVerificationProvider('123456');
        $this->app->instance(PhoneVerificationProviderInterface::class, $this->provider);
    }

    private function enableTestCode(string $code = self::TEST_CODE, array $environments = ['testing']): void
    {
        config([
            'phone.otp_test_code' => $code,
            'phone.otp_test_code_environments' => $environments,
        ]);
    }

    private function studentWithPendingPhone(): User
    {
        $user = User::factory()->create([
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ]);

        $user->phoneNumbers()->create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'country_iso2' => 'EG',
            'country_code' => '+20',
            'national_number' => '201234567890',
            'e164_number' => '+201234567890',
            'is_primary' => true,
            'status' => PhoneStatus::Pending,
        ]);

        return $user;
    }

    // ─── Shipped default: disabled ───────────────────────────────────────────

    public function test_the_test_code_is_disabled_by_default(): void
    {
        $this->assertNull(config('phone.otp_test_code'));
        $this->assertSame([], config('phone.otp_test_code_environments'));
    }

    public function test_the_test_code_is_rejected_when_no_configuration_is_present(): void
    {
        $user = $this->studentWithPendingPhone();
        Sanctum::actingAs($user);

        $this->postJson(self::SEND_URL)->assertOk();

        $this->postJson(self::VERIFY_URL, ['code' => self::TEST_CODE])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PHONE_OTP_INVALID');

        $this->assertSame(PhoneStatus::Pending, $user->primaryPhoneNumber()->status);

        // It was treated as an ordinary wrong code and forwarded to the provider.
        $this->assertSame([self::TEST_CODE], array_column($this->provider->checked, 'code'));
    }

    // ─── Enabled on a permitted environment ──────────────────────────────────

    public function test_the_test_code_verifies_the_phone_end_to_end(): void
    {
        $this->enableTestCode();

        $user = $this->studentWithPendingPhone();
        Sanctum::actingAs($user);

        $this->postJson(self::SEND_URL)->assertOk();

        $this->postJson(self::VERIFY_URL, ['code' => self::TEST_CODE])
            ->assertOk()
            ->assertJsonPath('data.phone_verified', true)
            ->assertJsonPath('data.user.phone_verified', true);

        $phone = $user->primaryPhoneNumber();

        $this->assertSame(PhoneStatus::Verified, $phone->status);
        $this->assertNotNull($phone->verified_at);
        $this->assertSame(OtpStatus::Verified, $phone->otps()->sole()->status);
    }

    public function test_the_test_code_never_reaches_the_verification_provider(): void
    {
        $this->enableTestCode();

        Sanctum::actingAs($this->studentWithPendingPhone());

        $this->postJson(self::SEND_URL)->assertOk();
        $this->postJson(self::VERIFY_URL, ['code' => self::TEST_CODE])->assertOk();

        // Sending still goes through the provider; checking does not.
        $this->assertSame(1, $this->provider->sendCount());
        $this->assertCount(0, $this->provider->checked);
    }

    public function test_the_test_code_still_consumes_an_attempt(): void
    {
        $this->enableTestCode();

        $user = $this->studentWithPendingPhone();
        Sanctum::actingAs($user);

        $this->postJson(self::SEND_URL)->assertOk();
        $this->postJson(self::VERIFY_URL, ['code' => self::TEST_CODE])->assertOk();

        $this->assertSame(1, $user->primaryPhoneNumber()->otps()->sole()->attempts);
    }

    public function test_the_real_code_still_works_while_the_test_code_is_enabled(): void
    {
        $this->enableTestCode();

        $user = $this->studentWithPendingPhone();
        Sanctum::actingAs($user);

        $this->postJson(self::SEND_URL)->assertOk();
        $this->postJson(self::VERIFY_URL, ['code' => '123456'])->assertOk();

        $this->assertSame(PhoneStatus::Verified, $user->primaryPhoneNumber()->status);
        $this->assertCount(1, $this->provider->checked);
    }

    public function test_a_wrong_code_is_still_rejected_while_the_test_code_is_enabled(): void
    {
        $this->enableTestCode();

        $user = $this->studentWithPendingPhone();
        Sanctum::actingAs($user);

        $this->postJson(self::SEND_URL)->assertOk();

        $this->postJson(self::VERIFY_URL, ['code' => '000000'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PHONE_OTP_INVALID');

        $this->assertSame(PhoneStatus::Pending, $user->primaryPhoneNumber()->status);
    }

    public function test_the_test_code_cannot_bypass_the_need_for_an_outstanding_code(): void
    {
        $this->enableTestCode();

        $user = $this->studentWithPendingPhone();
        Sanctum::actingAs($user);

        // No send call, so there is no pending OTP to consume.
        $this->postJson(self::VERIFY_URL, ['code' => self::TEST_CODE])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PHONE_OTP_NOT_FOUND');

        $this->assertSame(PhoneStatus::Pending, $user->primaryPhoneNumber()->status);
    }

    public function test_the_test_code_cannot_bypass_expiry(): void
    {
        $this->enableTestCode();

        $user = $this->studentWithPendingPhone();
        Sanctum::actingAs($user);

        $this->postJson(self::SEND_URL)->assertOk();
        $this->travel(config('phone.otp_expiry_minutes') + 1)->minutes();

        $this->postJson(self::VERIFY_URL, ['code' => self::TEST_CODE])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PHONE_OTP_EXPIRED');

        $this->assertSame(PhoneStatus::Pending, $user->primaryPhoneNumber()->status);
    }

    public function test_the_test_code_cannot_bypass_the_attempt_limit(): void
    {
        $this->enableTestCode();

        $user = $this->studentWithPendingPhone();
        Sanctum::actingAs($user);

        $this->postJson(self::SEND_URL)->assertOk();

        for ($i = 0; $i < (int) config('phone.otp_max_attempts'); $i++) {
            $this->postJson(self::VERIFY_URL, ['code' => '000000'])->assertStatus(422);
        }

        $this->postJson(self::VERIFY_URL, ['code' => self::TEST_CODE])
            ->assertStatus(429)
            ->assertJsonPath('code', 'PHONE_OTP_MAX_ATTEMPTS_EXCEEDED');

        $this->assertSame(PhoneStatus::Pending, $user->primaryPhoneNumber()->status);
    }

    public function test_the_test_code_cannot_verify_another_students_phone(): void
    {
        $this->enableTestCode();

        $caller = $this->studentWithPendingPhone();

        $victim = User::factory()->create(['status' => AccountStatus::Active]);
        $victimPhone = $victim->phoneNumbers()->create([
            'owner_type' => $victim->getMorphClass(),
            'owner_id' => $victim->getKey(),
            'country_iso2' => 'EG',
            'country_code' => '+20',
            'national_number' => '201119998888',
            'e164_number' => '+201119998888',
            'is_primary' => true,
            'status' => PhoneStatus::Pending,
        ]);

        Sanctum::actingAs($caller);
        $this->postJson(self::SEND_URL)->assertOk();

        $this->postJson(self::VERIFY_URL, [
            'code' => self::TEST_CODE,
            'phone_id' => $victimPhone->id,
        ])->assertOk();

        $this->assertSame(PhoneStatus::Verified, $caller->primaryPhoneNumber()->status);
        $this->assertSame(PhoneStatus::Pending, $victimPhone->fresh()->status);
    }

    // ─── Refused outside the permitted environments ──────────────────────────

    public function test_the_test_code_is_refused_in_production(): void
    {
        // Even with production explicitly allow-listed, which is the worst-case
        // misconfiguration this guard exists for.
        $this->enableTestCode(environments: ['production', 'testing']);
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->assertProductionStyleRejection();
    }

    public function test_the_test_code_is_refused_when_running_in_production_with_a_local_allow_list(): void
    {
        // The realistic leak: a local/testing allow-list shipped to production.
        // The environment check must refuse it even though production is not
        // named in the list at all.
        $this->enableTestCode(environments: ['local', 'testing']);
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->assertTrue($this->app->environment('production'));

        $this->assertProductionStyleRejection();
    }

    public function test_the_test_code_is_refused_when_the_environment_is_not_listed(): void
    {
        $this->enableTestCode(environments: ['local']);
        $this->app->detectEnvironment(fn (): string => 'staging');

        $this->assertProductionStyleRejection();
    }

    public function test_the_test_code_is_refused_on_staging_even_when_listed_alongside_local(): void
    {
        $this->enableTestCode(environments: ['local', 'testing']);
        $this->app->detectEnvironment(fn (): string => 'staging');

        $this->assertProductionStyleRejection();
    }

    public function test_the_test_code_is_refused_when_the_allow_list_is_empty(): void
    {
        $this->enableTestCode(environments: []);

        $this->assertProductionStyleRejection();
    }

    public function test_the_test_code_is_refused_when_the_code_is_not_configured(): void
    {
        config([
            'phone.otp_test_code' => null,
            'phone.otp_test_code_environments' => ['testing'],
        ]);

        $this->assertProductionStyleRejection();
    }

    public function test_an_empty_configured_code_does_not_accept_an_empty_submission(): void
    {
        $this->enableTestCode(code: '');

        $user = $this->studentWithPendingPhone();
        Sanctum::actingAs($user);

        $this->postJson(self::SEND_URL)->assertOk();

        // An empty code cannot even reach the service: validation refuses it.
        $this->postJson(self::VERIFY_URL, ['code' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->assertSame(PhoneStatus::Pending, $user->primaryPhoneNumber()->status);
    }

    /**
     * The test code behaves exactly like any other wrong code: refused, phone
     * left pending, and handed to the real provider rather than short-circuited.
     */
    private function assertProductionStyleRejection(): void
    {
        $user = $this->studentWithPendingPhone();
        Sanctum::actingAs($user);

        $this->postJson(self::SEND_URL)->assertOk();

        $this->postJson(self::VERIFY_URL, ['code' => self::TEST_CODE])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PHONE_OTP_INVALID');

        $this->assertSame(PhoneStatus::Pending, $user->primaryPhoneNumber()->status);
        $this->assertSame([self::TEST_CODE], array_column($this->provider->checked, 'code'));
    }

    // ─── The log provider used alongside it in local development ─────────────

    public function test_the_log_provider_sends_nothing_and_approves_nothing(): void
    {
        Log::spy();

        $this->app->instance(PhoneVerificationProviderInterface::class, new LogPhoneVerificationProvider);

        $user = $this->studentWithPendingPhone();
        Sanctum::actingAs($user);

        $this->postJson(self::SEND_URL)->assertOk();

        // On its own it approves nothing, so an arbitrary code still fails.
        $this->postJson(self::VERIFY_URL, ['code' => '123456'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PHONE_OTP_INVALID');

        $this->assertSame(PhoneStatus::Pending, $user->primaryPhoneNumber()->status);

        Log::shouldHaveReceived('info')->atLeast()->once();
    }

    public function test_the_log_provider_and_test_code_complete_verification_together(): void
    {
        $this->enableTestCode();
        $this->app->instance(PhoneVerificationProviderInterface::class, new LogPhoneVerificationProvider);

        $user = $this->studentWithPendingPhone();
        Sanctum::actingAs($user);

        $this->postJson(self::SEND_URL)->assertOk();
        $this->postJson(self::VERIFY_URL, ['code' => self::TEST_CODE])
            ->assertOk()
            ->assertJsonPath('data.phone_verified', true);

        $this->assertSame(PhoneStatus::Verified, $user->primaryPhoneNumber()->status);
    }

    public function test_the_log_provider_is_resolvable_through_configuration(): void
    {
        $this->app->forgetInstance(PhoneVerificationProviderInterface::class);
        config(['phone.default_provider' => 'log']);

        $this->assertInstanceOf(
            LogPhoneVerificationProvider::class,
            $this->app->make(PhoneVerificationProviderInterface::class),
        );
    }

    public function test_the_default_provider_remains_twilio_verify(): void
    {
        $this->assertSame('twilio_verify', config('phone.default_provider'));
    }
}
