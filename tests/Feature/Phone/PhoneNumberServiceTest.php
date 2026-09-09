<?php

namespace Tests\Feature\Phone;

use App\Contracts\PhoneVerificationProviderInterface;
use App\Enums\OtpPurpose;
use App\Enums\OtpStatus;
use App\Enums\PhoneStatus;
use App\Exceptions\Phone\InvalidPhoneException;
use App\Exceptions\Phone\MaxPhoneNumbersReachedException;
use App\Exceptions\Phone\PhoneAlreadyExistsException;
use App\Exceptions\Phone\VerificationException;
use App\Models\PhoneNumber;
use App\Models\PhoneVerificationOtp;
use App\Models\User;
use App\Services\Phone\PhoneNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class PhoneNumberServiceTest extends TestCase
{
    use RefreshDatabase;

    private PhoneNumberService $service;

    private MockInterface $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = $this->mock(PhoneVerificationProviderInterface::class);

        $this->service = new PhoneNumberService($this->provider);
    }

    // ─── addPhone ───────────────────────────────────────────────────────────────

    public function test_can_add_phone_to_a_polymorphic_owner(): void
    {
        $user = User::factory()->create();

        $phone = $this->service->addPhone($user, ['phone' => '+201012345678']);

        $this->assertDatabaseHas('phone_numbers', [
            'owner_type' => 'user',
            'owner_id' => $user->id,
            'e164_number' => '+201012345678',
        ]);

        $this->assertInstanceOf(PhoneNumber::class, $phone);
    }

    public function test_normalizes_phone_to_e164(): void
    {
        $user = User::factory()->create();

        $phone = $this->service->addPhone($user, [
            'phone' => '01012345678',
            'country' => 'EG',
        ]);

        $this->assertSame('+201012345678', $phone->e164_number);
    }

    public function test_rejects_invalid_phone(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidPhoneException::class);

        $this->service->addPhone($user, ['phone' => '00000', 'country' => 'EG']);
    }

    public function test_rejects_unsupported_country(): void
    {
        config()->set('phone.allowed_countries', ['EG', 'SA']);

        $user = User::factory()->create();

        $this->expectException(InvalidPhoneException::class);

        // US number — not in allowed list
        $this->service->addPhone($user, ['phone' => '+12025550123']);
    }

    public function test_prevents_duplicate_active_phone_for_same_owner(): void
    {
        config()->set('phone.unique_globally', false);

        $user = User::factory()->create();

        $this->service->addPhone($user, ['phone' => '+201012345678']);

        $this->expectException(PhoneAlreadyExistsException::class);

        $this->service->addPhone($user, ['phone' => '+201012345678']);
    }

    public function test_respects_unique_globally_true(): void
    {
        config()->set('phone.unique_globally', true);

        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $this->service->addPhone($userA, ['phone' => '+201012345678']);

        $this->expectException(PhoneAlreadyExistsException::class);

        $this->service->addPhone($userB, ['phone' => '+201012345678']);
    }

    public function test_respects_unique_globally_false(): void
    {
        config()->set('phone.unique_globally', false);

        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $this->service->addPhone($userA, ['phone' => '+201012345678']);
        $phoneB = $this->service->addPhone($userB, ['phone' => '+201012345678']);

        $this->assertDatabaseCount('phone_numbers', 2);
        $this->assertSame('+201012345678', $phoneB->e164_number);
    }

    public function test_enforces_max_numbers_per_owner(): void
    {
        config()->set('phone.max_numbers_per_owner', 2);
        config()->set('phone.unique_globally', false);

        $user = User::factory()->create();

        $this->service->addPhone($user, ['phone' => '+201012345678']);
        $this->service->addPhone($user, ['phone' => '+201098765432']);

        $this->expectException(MaxPhoneNumbersReachedException::class);

        $this->service->addPhone($user, ['phone' => '+201011112222']);
    }

    public function test_first_phone_is_automatically_set_as_primary(): void
    {
        $user = User::factory()->create();

        $phone = $this->service->addPhone($user, ['phone' => '+201012345678']);

        $this->assertTrue($phone->is_primary);
    }

    // ─── setPrimary ──────────────────────────────────────────────────────────────

    public function test_can_set_primary_and_unset_others(): void
    {
        config()->set('phone.unique_globally', false);

        $user = User::factory()->create();

        $first = $this->service->addPhone($user, ['phone' => '+201012345678']);
        $second = $this->service->addPhone($user, ['phone' => '+201098765432']);

        $this->assertTrue($first->fresh()->is_primary);

        $this->service->setPrimary($second);

        $this->assertFalse($first->fresh()->is_primary);
        $this->assertTrue($second->fresh()->is_primary);
    }

    // ─── updatePhone ─────────────────────────────────────────────────────────────

    public function test_editing_phone_resets_verification_when_number_changes(): void
    {
        $user = User::factory()->create();

        $phone = $this->service->addPhone($user, ['phone' => '+201012345678']);
        $phone->update(['status' => PhoneStatus::Verified, 'verified_at' => now()]);

        config()->set('phone.unique_globally', false);
        $updated = $this->service->updatePhone($phone, ['phone' => '+201098765432']);

        $this->assertSame(PhoneStatus::Pending, $updated->status);
        $this->assertNull($updated->verified_at);
    }

    public function test_updating_phone_with_same_number_does_not_reset_verification(): void
    {
        $user = User::factory()->create();

        $phone = $this->service->addPhone($user, ['phone' => '+201012345678']);
        $phone->update(['status' => PhoneStatus::Verified, 'verified_at' => now()]);

        $updated = $this->service->updatePhone($phone, ['phone' => '+201012345678']);

        $this->assertSame(PhoneStatus::Verified, $updated->status);
        $this->assertNotNull($updated->verified_at);
    }

    // ─── deletePhone ─────────────────────────────────────────────────────────────

    public function test_soft_deleted_phone_is_excluded_from_list(): void
    {
        $user = User::factory()->create();
        $phone = $this->service->addPhone($user, ['phone' => '+201012345678']);

        $this->service->deletePhone($phone);

        $this->assertCount(0, $this->service->listPhones($user));
        $this->assertSoftDeleted('phone_numbers', ['id' => $phone->id]);
    }

    public function test_deleting_primary_phone_promotes_another_as_primary(): void
    {
        config()->set('phone.unique_globally', false);

        $user = User::factory()->create();

        $first = $this->service->addPhone($user, ['phone' => '+201012345678']);
        $second = $this->service->addPhone($user, ['phone' => '+201098765432']);

        $this->assertTrue($first->fresh()->is_primary);

        $this->service->deletePhone($first);

        $this->assertTrue($second->fresh()->is_primary);
    }

    // ─── sendVerification ────────────────────────────────────────────────────────

    public function test_send_verification_creates_otp_record_and_calls_provider(): void
    {
        $user = User::factory()->create();
        $phone = $this->service->addPhone($user, ['phone' => '+201012345678']);

        $this->provider
            ->shouldReceive('sendVerification')
            ->once()
            ->with('+201012345678', 'sms', OtpPurpose::PhoneVerification->value, [])
            ->andReturn([
                'provider_reference' => 'VEtestref123',
                'status' => 'pending',
                'channel' => 'sms',
            ]);

        $otp = $this->service->sendVerification($phone);

        $this->assertDatabaseHas('phone_verification_otps', [
            'phone_number_id' => $phone->id,
            'purpose' => OtpPurpose::PhoneVerification->value,
            'status' => OtpStatus::Pending->value,
            'code_hash' => 'VEtestref123',
        ]);

        $this->assertInstanceOf(PhoneVerificationOtp::class, $otp);
    }

    public function test_send_verification_expires_previous_pending_otps(): void
    {
        $user = User::factory()->create();
        $phone = $this->service->addPhone($user, ['phone' => '+201012345678']);

        $this->provider
            ->shouldReceive('sendVerification')
            ->twice()
            ->andReturn(
                ['provider_reference' => 'VEfirst', 'status' => 'pending', 'channel' => 'sms'],
                ['provider_reference' => 'VEsecond', 'status' => 'pending', 'channel' => 'sms']
            );

        $first = $this->service->sendVerification($phone);
        $this->service->sendVerification($phone);

        $this->assertSame(OtpStatus::Expired, $first->fresh()->status);
    }

    public function test_send_verification_rejects_unsupported_channel(): void
    {
        $user = User::factory()->create();
        $phone = $this->service->addPhone($user, ['phone' => '+201012345678']);

        $this->expectException(VerificationException::class);

        $this->service->sendVerification($phone, channel: 'whatsapp');
    }

    // ─── verifyPhone ─────────────────────────────────────────────────────────────

    public function test_verify_phone_marks_phone_as_verified_on_success(): void
    {
        $user = User::factory()->create();
        $phone = $this->service->addPhone($user, ['phone' => '+201012345678']);

        $this->provider
            ->shouldReceive('sendVerification')
            ->once()
            ->andReturn(['provider_reference' => 'VEref', 'status' => 'pending', 'channel' => 'sms']);

        $this->provider
            ->shouldReceive('checkVerification')
            ->once()
            ->with('+201012345678', '123456', OtpPurpose::PhoneVerification->value, [])
            ->andReturn(['valid' => true, 'status' => 'approved']);

        $this->service->sendVerification($phone);

        $result = $this->service->verifyPhone($phone, '123456');

        $this->assertTrue($result);
        $this->assertSame(PhoneStatus::Verified, $phone->fresh()->status);
        $this->assertNotNull($phone->fresh()->verified_at);
    }

    public function test_verify_phone_returns_false_on_wrong_code(): void
    {
        $user = User::factory()->create();
        $phone = $this->service->addPhone($user, ['phone' => '+201012345678']);

        $this->provider
            ->shouldReceive('sendVerification')
            ->once()
            ->andReturn(['provider_reference' => 'VEref', 'status' => 'pending', 'channel' => 'sms']);

        $this->provider
            ->shouldReceive('checkVerification')
            ->once()
            ->andReturn(['valid' => false, 'status' => 'pending']);

        $this->service->sendVerification($phone);

        $result = $this->service->verifyPhone($phone, '000000');

        $this->assertFalse($result);
        $this->assertSame(PhoneStatus::Pending, $phone->fresh()->status);
    }

    public function test_verify_phone_throws_when_no_pending_otp(): void
    {
        $user = User::factory()->create();
        $phone = $this->service->addPhone($user, ['phone' => '+201012345678']);

        $this->expectException(VerificationException::class);

        $this->service->verifyPhone($phone, '123456');
    }

    public function test_verify_phone_throws_when_otp_is_expired(): void
    {
        $user = User::factory()->create();
        $phone = $this->service->addPhone($user, ['phone' => '+201012345678']);

        $this->provider
            ->shouldReceive('sendVerification')
            ->once()
            ->andReturn(['provider_reference' => 'VEref', 'status' => 'pending', 'channel' => 'sms']);

        $otp = $this->service->sendVerification($phone);
        $otp->update(['expires_at' => now()->subMinute()]);

        $this->expectException(VerificationException::class);

        $this->service->verifyPhone($phone, '123456');
    }

    public function test_verify_phone_marks_otp_as_failed_after_max_attempts(): void
    {
        $user = User::factory()->create();
        $phone = $this->service->addPhone($user, ['phone' => '+201012345678']);

        $this->provider
            ->shouldReceive('sendVerification')
            ->once()
            ->andReturn(['provider_reference' => 'VEref', 'status' => 'pending', 'channel' => 'sms']);

        $this->provider
            ->shouldReceive('checkVerification')
            ->times(5)
            ->andReturn(['valid' => false, 'status' => 'pending']);

        $otp = $this->service->sendVerification($phone);
        $otp->update(['max_attempts' => 5]);

        for ($i = 0; $i < 5; $i++) {
            try {
                $this->service->verifyPhone($phone, '000000');
            } catch (VerificationException) {
                break;
            }
        }

        $this->assertSame(OtpStatus::Failed, $otp->fresh()->status);
    }

    // ─── Provider can be swapped via container ───────────────────────────────────

    public function test_twilio_provider_can_be_swapped_via_container(): void
    {
        $fake = new class implements PhoneVerificationProviderInterface
        {
            public function sendVerification(string $e164, string $channel, string $purpose, array $metadata = []): array
            {
                return ['provider_reference' => 'fake-ref', 'status' => 'pending', 'channel' => $channel];
            }

            public function checkVerification(string $e164, string $code, string $purpose, array $metadata = []): array
            {
                return ['valid' => $code === 'correct', 'status' => $code === 'correct' ? 'approved' : 'pending'];
            }
        };

        $this->app->instance(PhoneVerificationProviderInterface::class, $fake);

        $service = $this->app->make(PhoneNumberService::class);
        $user = User::factory()->create();
        $phone = $service->addPhone($user, ['phone' => '+201012345678']);

        $otp = $service->sendVerification($phone);

        $this->assertSame('fake-ref', $otp->code_hash);
        $this->assertTrue($service->verifyPhone($phone, 'correct'));
    }
}
