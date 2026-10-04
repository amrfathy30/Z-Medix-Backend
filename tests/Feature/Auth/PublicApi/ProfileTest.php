<?php

namespace Tests\Feature\Auth\PublicApi;

use App\Enums\AccountStatus;
use App\Enums\PhoneStatus;
use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Nnjeim\World\Models\Country;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'status' => AccountStatus::Active,
        ], $attributes));
    }

    private function country(string $iso2 = 'EG', string $name = 'Egypt', string $phoneCode = '20'): Country
    {
        return $this->createCountry($iso2, $name, $phoneCode);
    }

    private function phoneFor(User $user, PhoneStatus $status = PhoneStatus::Pending, string $e164 = '+201234567890'): PhoneNumber
    {
        return $user->phoneNumbers()->create([
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->getKey(),
            'country_iso2' => 'EG',
            'country_code' => '+20',
            'national_number' => ltrim($e164, '+'),
            'e164_number' => $e164,
            'is_primary' => true,
            'status' => $status,
        ]);
    }

    // ─── Show ─────────────────────────────────────────────────────────────────

    public function test_authenticated_user_can_view_profile(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $this->getJson('/api/public/profile')
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id', 'name', 'full_name', 'email', 'email_verified', 'is_email_verified',
                    'phone', 'phone_verified', 'country', 'institution', 'field_of_study',
                    'profile_photo', 'type', 'status', 'last_login_at',
                ],
            ])
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_unauthenticated_user_cannot_view_profile(): void
    {
        $this->getJson('/api/public/profile')->assertStatus(401);
    }

    public function test_profile_response_does_not_include_avatar(): void
    {
        Sanctum::actingAs($this->user());

        $data = $this->getJson('/api/public/profile')->assertOk()->json('data');

        $this->assertArrayNotHasKey('avatar', $data);
        $this->assertArrayNotHasKey('avatar_url', $data);
    }

    public function test_profile_response_does_not_include_student_or_lecturer_details(): void
    {
        Sanctum::actingAs($this->user());

        $data = $this->getJson('/api/public/profile')->assertOk()->json('data');

        $this->assertArrayNotHasKey('student', $data);
        $this->assertArrayNotHasKey('lecturer', $data);
        $this->assertArrayNotHasKey('university', $data);
        $this->assertArrayNotHasKey('bio', $data);
    }

    // ─── Update ───────────────────────────────────────────────────────────────

    public function test_full_name_mirrors_the_stored_name_column(): void
    {
        $user = $this->user(['name' => 'Yousra Ahmed']);
        Sanctum::actingAs($user);

        $this->getJson('/api/public/profile')
            ->assertOk()
            ->assertJsonPath('data.full_name', 'Yousra Ahmed')
            ->assertJsonPath('data.name', 'Yousra Ahmed');
    }

    public function test_full_name_can_be_updated_and_writes_to_the_name_column(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $this->patchJson('/api/public/profile', ['full_name' => 'Yousra Ahmed'])
            ->assertOk()
            ->assertJsonPath('data.full_name', 'Yousra Ahmed')
            ->assertJsonPath('data.name', 'Yousra Ahmed');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Yousra Ahmed']);
    }

    public function test_first_and_last_name_are_no_longer_exposed_in_the_profile_response(): void
    {
        Sanctum::actingAs($this->user(['first_name' => 'Alice', 'last_name' => 'Wonder']));

        $data = $this->getJson('/api/public/profile')->assertOk()->json('data');

        $this->assertArrayNotHasKey('first_name', $data);
        $this->assertArrayNotHasKey('last_name', $data);
    }

    public function test_first_and_last_name_can_still_be_stored(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $this->patchJson('/api/public/profile', ['first_name' => 'Bob', 'last_name' => 'Builder'])
            ->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'first_name' => 'Bob',
            'last_name' => 'Builder',
        ]);
    }

    public function test_authenticated_user_can_update_name_only(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $this->patchJson('/api/public/profile', ['name' => 'New Name'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.email', $user->email);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'New Name']);
    }

    public function test_profile_update_cannot_change_the_email_address(): void
    {
        Notification::fake();

        $user = $this->user(['email_verified_at' => now()]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/public/profile', ['email' => 'new@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->assertSame($user->email, $user->fresh()->email);
    }

    public function test_rejected_email_change_leaves_verification_and_status_untouched(): void
    {
        Notification::fake();

        $verifiedAt = now()->subMonth();
        $user = $this->user(['email_verified_at' => $verifiedAt]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/public/profile', [
            'full_name' => 'Updated Name',
            'email' => 'updated@example.com',
        ])->assertStatus(422);

        $user->refresh();

        $this->assertSame($verifiedAt->toDateTimeString(), $user->email_verified_at->toDateTimeString());
        $this->assertSame(AccountStatus::Active, $user->status);
        // The whole update is rejected, so the name is not applied either.
        $this->assertNotSame('Updated Name', $user->name);

        Notification::assertNothingSent();
    }

    public function test_rejected_email_change_sends_no_verification_notification(): void
    {
        Notification::fake();

        Sanctum::actingAs($this->user());

        $this->patchJson('/api/public/profile', ['email' => 'changed@example.com'])
            ->assertStatus(422);

        Notification::assertNotSentTo([$this->user()], VerifyEmail::class);
        Notification::assertNothingSent();
    }

    public function test_empty_profile_update_request_is_allowed(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $this->patchJson('/api/public/profile', [])
            ->assertOk()
            ->assertJsonPath('data.name', $user->name);
    }

    public function test_submitting_the_current_email_is_accepted_as_a_no_op(): void
    {
        $verifiedAt = now()->subMonth();
        $user = $this->user(['email_verified_at' => $verifiedAt]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/public/profile', ['email' => $user->email])->assertOk();

        $this->assertSame($verifiedAt->toDateTimeString(), $user->fresh()->email_verified_at->toDateTimeString());
    }

    public function test_invalid_email_fails_validation(): void
    {
        Sanctum::actingAs($this->user());

        $this->patchJson('/api/public/profile', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_duplicate_email_fails_validation(): void
    {
        $existing = $this->user(['email' => 'taken@example.com']);
        Sanctum::actingAs($this->user());

        $this->patchJson('/api/public/profile', ['email' => 'taken@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_unchanged_email_does_not_reset_email_verified_at(): void
    {
        $user = $this->user(['email_verified_at' => now()]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/public/profile', ['email' => $user->email])->assertOk();

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    // ─── Institution and field of study ──────────────────────────────────────

    public function test_institution_and_field_of_study_are_returned(): void
    {
        Sanctum::actingAs($this->user([
            'institution' => 'Cairo University',
            'field_of_study' => 'Medicine',
        ]));

        $this->getJson('/api/public/profile')
            ->assertOk()
            ->assertJsonPath('data.institution', 'Cairo University')
            ->assertJsonPath('data.field_of_study', 'Medicine');
    }

    public function test_institution_and_field_of_study_may_be_null(): void
    {
        Sanctum::actingAs($this->user());

        $this->getJson('/api/public/profile')
            ->assertOk()
            ->assertJsonPath('data.institution', null)
            ->assertJsonPath('data.field_of_study', null);
    }

    public function test_institution_can_be_added_changed_and_cleared(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $this->patchJson('/api/public/profile', ['institution' => 'Cairo University'])
            ->assertOk()
            ->assertJsonPath('data.institution', 'Cairo University');

        $this->patchJson('/api/public/profile', ['institution' => 'Ain Shams University'])
            ->assertOk()
            ->assertJsonPath('data.institution', 'Ain Shams University');

        $this->patchJson('/api/public/profile', ['institution' => null])
            ->assertOk()
            ->assertJsonPath('data.institution', null);

        $this->assertNull($user->fresh()->institution);
    }

    public function test_field_of_study_can_be_added_changed_and_cleared(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $this->patchJson('/api/public/profile', ['field_of_study' => 'Medicine'])
            ->assertOk()
            ->assertJsonPath('data.field_of_study', 'Medicine');

        $this->patchJson('/api/public/profile', ['field_of_study' => 'Dentistry'])
            ->assertOk()
            ->assertJsonPath('data.field_of_study', 'Dentistry');

        $this->patchJson('/api/public/profile', ['field_of_study' => null])
            ->assertOk()
            ->assertJsonPath('data.field_of_study', null);

        $this->assertNull($user->fresh()->field_of_study);
    }

    public function test_both_new_text_fields_are_optional(): void
    {
        $user = $this->user(['institution' => 'Cairo University', 'field_of_study' => 'Medicine']);
        Sanctum::actingAs($user);

        $this->patchJson('/api/public/profile', ['full_name' => 'Only The Name'])->assertOk();

        $user->refresh();

        $this->assertSame('Cairo University', $user->institution);
        $this->assertSame('Medicine', $user->field_of_study);
    }

    public function test_new_text_fields_enforce_max_length(): void
    {
        Sanctum::actingAs($this->user());

        $this->patchJson('/api/public/profile', [
            'institution' => str_repeat('a', 256),
            'field_of_study' => str_repeat('b', 256),
        ])->assertStatus(422)->assertJsonValidationErrors(['institution', 'field_of_study']);
    }

    // ─── Country ─────────────────────────────────────────────────────────────

    public function test_country_is_returned_as_an_object(): void
    {
        $egypt = $this->country();
        Sanctum::actingAs($this->user(['country_id' => $egypt->id]));

        $this->getJson('/api/public/profile')
            ->assertOk()
            ->assertJsonPath('data.country.id', $egypt->id)
            ->assertJsonPath('data.country.name', 'Egypt');
    }

    public function test_country_is_null_when_unset(): void
    {
        Sanctum::actingAs($this->user());

        $this->getJson('/api/public/profile')->assertOk()->assertJsonPath('data.country', null);
    }

    public function test_country_can_be_updated(): void
    {
        $this->country();
        $saudi = $this->country('SA', 'Saudi Arabia', '966');

        $user = $this->user();
        Sanctum::actingAs($user);

        $this->patchJson('/api/public/profile', ['country_id' => $saudi->id])
            ->assertOk()
            ->assertJsonPath('data.country.id', $saudi->id)
            ->assertJsonPath('data.country.name', 'Saudi Arabia');

        $this->assertSame($saudi->id, $user->fresh()->country_id);
    }

    public function test_unknown_country_is_rejected(): void
    {
        Sanctum::actingAs($this->user());

        $this->patchJson('/api/public/profile', ['country_id' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['country_id']);
    }

    // ─── Email verification state ────────────────────────────────────────────

    public function test_email_verified_is_true_when_the_timestamp_is_set(): void
    {
        Sanctum::actingAs($this->user(['email_verified_at' => now()]));

        $this->getJson('/api/public/profile')
            ->assertOk()
            ->assertJsonPath('data.email_verified', true);
    }

    public function test_email_verified_is_false_when_the_timestamp_is_null(): void
    {
        Sanctum::actingAs($this->user(['email_verified_at' => null]));

        $this->getJson('/api/public/profile')
            ->assertOk()
            ->assertJsonPath('data.email_verified', false);
    }

    // ─── Phone and phone verification state ──────────────────────────────────

    public function test_phone_and_phone_verified_are_returned_for_a_verified_phone(): void
    {
        $user = $this->user();
        $this->phoneFor($user, PhoneStatus::Verified);
        Sanctum::actingAs($user);

        $this->getJson('/api/public/profile')
            ->assertOk()
            ->assertJsonPath('data.phone', '+201234567890')
            ->assertJsonPath('data.phone_verified', true);
    }

    public function test_phone_verified_is_false_for_an_unverified_phone(): void
    {
        $user = $this->user();
        $this->phoneFor($user, PhoneStatus::Pending);
        Sanctum::actingAs($user);

        $this->getJson('/api/public/profile')
            ->assertOk()
            ->assertJsonPath('data.phone', '+201234567890')
            ->assertJsonPath('data.phone_verified', false);
    }

    public function test_phone_is_null_and_unverified_when_the_student_has_no_phone(): void
    {
        Sanctum::actingAs($this->user());

        $this->getJson('/api/public/profile')
            ->assertOk()
            ->assertJsonPath('data.phone', null)
            ->assertJsonPath('data.phone_verified', false);
    }

    public function test_phone_verified_describes_the_primary_phone_that_is_returned(): void
    {
        $user = $this->user();

        // Primary number is verified; an unrelated secondary number is not.
        $this->phoneFor($user, PhoneStatus::Verified, '+201234567890');
        $secondary = $this->phoneFor($user, PhoneStatus::Pending, '+201119998888');
        $secondary->update(['is_primary' => false]);

        Sanctum::actingAs($user);

        $this->getJson('/api/public/profile')
            ->assertOk()
            ->assertJsonPath('data.phone', '+201234567890')
            ->assertJsonPath('data.phone_verified', true);
    }

    public function test_phone_verified_follows_the_primary_phone_when_the_secondary_is_verified(): void
    {
        $user = $this->user();

        $primary = $this->phoneFor($user, PhoneStatus::Pending, '+201234567890');
        $secondary = $this->phoneFor($user, PhoneStatus::Verified, '+201119998888');
        $secondary->update(['is_primary' => false]);

        Sanctum::actingAs($user);

        $this->getJson('/api/public/profile')
            ->assertOk()
            ->assertJsonPath('data.phone', $primary->e164_number)
            ->assertJsonPath('data.phone_verified', false);
    }

    public function test_phone_can_be_updated_through_the_phone_numbers_architecture(): void
    {
        $this->country();
        $user = $this->user(['country_id' => $this->country('SA', 'Saudi Arabia', '966')->id]);
        $this->phoneFor($user, PhoneStatus::Verified);
        Sanctum::actingAs($user);

        $this->patchJson('/api/public/profile', ['phone' => '+201119998888'])
            ->assertOk()
            ->assertJsonPath('data.phone', '+201119998888');

        $this->assertSame(1, $user->phoneNumbers()->count());
        $this->assertFalse(Schema::hasColumn('users', 'phone'));
    }

    public function test_phone_can_be_added_when_the_student_has_none(): void
    {
        $user = $this->user(['country_id' => $this->country()->id]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/public/profile', ['phone' => '+201119998888'])
            ->assertOk()
            ->assertJsonPath('data.phone', '+201119998888')
            ->assertJsonPath('data.phone_verified', false);

        $this->assertTrue($user->phoneNumbers()->sole()->is_primary);
    }

    public function test_invalid_phone_is_rejected(): void
    {
        $user = $this->user(['country_id' => $this->country()->id]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/public/profile', ['phone' => '12'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_PHONE');

        $this->assertSame(0, $user->phoneNumbers()->count());
    }

    // ─── Verification fields are read-only ───────────────────────────────────

    public function test_verification_booleans_cannot_be_spoofed_through_profile_update(): void
    {
        $user = $this->user(['email_verified_at' => null]);
        $this->phoneFor($user, PhoneStatus::Pending);
        Sanctum::actingAs($user);

        $this->patchJson('/api/public/profile', [
            'email_verified' => true,
            'is_email_verified' => true,
            'email_verified_at' => now()->toIso8601String(),
            'phone_verified' => true,
            'status' => AccountStatus::Blocked->value,
            'type' => 'super_admin',
        ])->assertOk()
            ->assertJsonPath('data.email_verified', false)
            ->assertJsonPath('data.phone_verified', false)
            ->assertJsonPath('data.status', AccountStatus::Active->value);

        $user->refresh();

        $this->assertNull($user->email_verified_at);
        $this->assertSame(AccountStatus::Active, $user->status);
        $this->assertSame(PhoneStatus::Pending, $user->primaryPhoneNumber()->status);
    }

    // ─── Guard rails ─────────────────────────────────────────────────────────

    public function test_registration_is_only_exposed_on_the_public_auth_prefix(): void
    {
        // Student registration now exists; 422 proves the route is reachable and
        // validated. It must not be mirrored on the admin prefix.
        $this->postJson('/api/public/auth/register', [])->assertStatus(422);
        $this->postJson('/api/admin/auth/register', [])->assertStatus(404);
    }

    public function test_no_phone_routes_exist(): void
    {
        $this->postJson('/api/public/auth/phone/login', [])->assertStatus(404);
    }

    public function test_no_admin_profile_routes_exist(): void
    {
        $this->getJson('/api/admin/profile')->assertStatus(404);
    }
}
