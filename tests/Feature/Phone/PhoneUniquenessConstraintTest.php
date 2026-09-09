<?php

namespace Tests\Feature\Phone;

use App\Contracts\PhoneVerificationProviderInterface;
use App\Enums\PhoneStatus;
use App\Exceptions\Phone\PhoneAlreadyExistsException;
use App\Models\PhoneNumber;
use App\Models\User;
use App\Services\Phone\PhoneNumberService;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Covers both ordinary duplicate pre-checks and deterministic lost-race translation without runtime DDL. */
class PhoneUniquenessConstraintTest extends TestCase
{
    use RefreshDatabase;

    private PhoneNumberService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(PhoneVerificationProviderInterface::class);
        $this->service = app(PhoneNumberService::class);
    }

    private function failNextTransactionWithUniqueConstraintViolation(): void
    {
        DB::shouldReceive('transaction')->once()->andThrow(new UniqueConstraintViolationException(
            'testing',
            'insert into phone_numbers (...) values (...)',
            [],
            new \PDOException('Duplicate entry', 23000),
        ));
    }

    public function test_duplicate_add_for_another_owner_is_a_domain_error(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $this->service->addPhone($ownerA, ['phone' => '+201012345678']);
        $this->expectException(PhoneAlreadyExistsException::class);
        $this->service->addPhone($ownerB, ['phone' => '+201012345678']);
    }

    public function test_duplicate_add_for_the_same_owner_is_a_domain_error(): void
    {
        $owner = User::factory()->create();
        $this->service->addPhone($owner, ['phone' => '+201012345678']);
        $this->expectException(PhoneAlreadyExistsException::class);
        $this->service->addPhone($owner, ['phone' => '+201012345678']);
    }

    public function test_duplicate_update_is_a_domain_error(): void
    {
        $owner = User::factory()->create();
        $this->service->addPhone($owner, ['phone' => '+201012345678']);
        $second = $this->service->addPhone($owner, ['phone' => '+201098765432']);
        $this->expectException(PhoneAlreadyExistsException::class);
        $this->service->updatePhone($second, ['phone' => '+201012345678']);
    }

    public function test_rejected_duplicate_leaves_existing_records_consistent(): void
    {
        $owner = User::factory()->create();
        $first = $this->service->addPhone($owner, ['phone' => '+201012345678']);
        $second = $this->service->addPhone($owner, ['phone' => '+201098765432']);

        try {
            $this->service->updatePhone($second, ['phone' => '+201012345678']);
            $this->fail('Expected duplicate update to be refused.');
        } catch (PhoneAlreadyExistsException) {
        }

        $this->assertSame('+201012345678', $first->fresh()->e164_number);
        $this->assertSame('+201098765432', $second->fresh()->e164_number);
        $this->assertSame(2, PhoneNumber::count());
    }

    public function test_a_refused_add_creates_no_row(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $this->service->addPhone($ownerA, ['phone' => '+201012345678']);

        try {
            $this->service->addPhone($ownerB, ['phone' => '+201012345678']);
            $this->fail('Expected duplicate add to be refused.');
        } catch (PhoneAlreadyExistsException) {
        }

        $this->assertSame(1, PhoneNumber::count());
        $this->assertSame(0, $ownerB->phoneNumbers()->count());
    }

    public function test_lost_race_on_add_surfaces_as_a_domain_error(): void
    {
        config()->set('phone.unique_globally', false);
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $this->service->addPhone($ownerA, ['phone' => '+201012345678']);
        $this->failNextTransactionWithUniqueConstraintViolation();
        $this->expectException(PhoneAlreadyExistsException::class);
        $this->service->addPhone($ownerB, ['phone' => '+201012345678']);
    }

    public function test_lost_race_on_update_surfaces_as_a_domain_error(): void
    {
        config()->set('phone.unique_globally', false);
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $this->service->addPhone($ownerA, ['phone' => '+201012345678']);
        $phoneB = $this->service->addPhone($ownerB, ['phone' => '+201098765432']);
        $this->failNextTransactionWithUniqueConstraintViolation();
        $this->expectException(PhoneAlreadyExistsException::class);
        $this->service->updatePhone($phoneB, ['phone' => '+201012345678']);
    }

    public function test_lost_race_message_carries_no_database_detail(): void
    {
        config()->set('phone.unique_globally', false);
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $this->service->addPhone($ownerA, ['phone' => '+201012345678']);
        $this->failNextTransactionWithUniqueConstraintViolation();

        try {
            $this->service->addPhone($ownerB, ['phone' => '+201012345678']);
            $this->fail('Expected lost race to be refused.');
        } catch (PhoneAlreadyExistsException $e) {
            $this->assertStringNotContainsStringIgnoringCase('SQLSTATE', $e->getMessage());
            $this->assertStringNotContainsStringIgnoringCase('unique', $e->getMessage());
            $this->assertStringContainsString('+201012345678', $e->getMessage());
        }
    }

    public function test_lost_race_on_update_leaves_the_row_untouched(): void
    {
        config()->set('phone.unique_globally', false);
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $this->service->addPhone($ownerA, ['phone' => '+201012345678']);
        $phoneB = $this->service->addPhone($ownerB, ['phone' => '+201098765432']);
        $phoneB->update(['status' => PhoneStatus::Verified, 'verified_at' => now()]);
        $this->failNextTransactionWithUniqueConstraintViolation();

        try {
            $this->service->updatePhone($phoneB, ['phone' => '+201012345678']);
            $this->fail('Expected lost race to be refused.');
        } catch (PhoneAlreadyExistsException) {
        }

        $phoneB->refresh();
        $this->assertSame('+201098765432', $phoneB->e164_number);
        $this->assertSame(PhoneStatus::Verified, $phoneB->status);
        $this->assertNotNull($phoneB->verified_at);
        $this->assertSame(2, PhoneNumber::count());
    }

    public function test_normal_add_and_update_still_write_successfully_without_a_test_constraint(): void
    {
        config()->set('phone.unique_globally', false);
        $owner = User::factory()->create();
        $phone = $this->service->addPhone($owner, ['phone' => '+201012345678']);
        $updated = $this->service->updatePhone($phone, ['phone' => '+201098765432']);
        $this->assertSame('+201098765432', $updated->e164_number);
    }

    public function test_unique_violation_is_a_query_exception_subclass(): void
    {
        $this->assertTrue(is_subclass_of(UniqueConstraintViolationException::class, QueryException::class));
    }
}
