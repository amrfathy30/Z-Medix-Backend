<?php

namespace Tests\Feature\Auth\PublicApi;

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfilePhotoTest extends TestCase
{
    use RefreshDatabase;

    private string $url = '/api/public/profile';

    protected function setUp(): void
    {
        parent::setUp();

        // The project's media convention stores public files on this disk.
        Storage::fake('filament_public');
    }

    private function student(): User
    {
        return User::factory()->create(['status' => AccountStatus::Active]);
    }

    public function test_profile_photo_is_null_when_no_media_exists(): void
    {
        Sanctum::actingAs($this->student());

        $this->getJson($this->url)
            ->assertOk()
            ->assertJsonPath('data.profile_photo', null);
    }

    public function test_profile_photo_can_be_uploaded_and_the_url_is_returned(): void
    {
        $user = $this->student();
        Sanctum::actingAs($user);

        $response = $this->patch($this->url, [
            'profile_photo' => UploadedFile::fake()->image('avatar.jpg', 320, 320),
        ], ['Accept' => 'application/json'])->assertOk();

        $url = $response->json('data.profile_photo');

        $this->assertIsString($url);
        $this->assertStringContainsString('avatar.jpg', $url);
        $this->assertStringStartsWith('http', $url, 'The URL must be resolved through the project media URL convention.');

        $this->assertSame(1, $user->fresh()->getMedia(User::PROFILE_PHOTO_COLLECTION)->count());
    }

    public function test_media_is_stored_in_the_profile_photo_collection(): void
    {
        $user = $this->student();
        Sanctum::actingAs($user);

        $this->patch($this->url, [
            'profile_photo' => UploadedFile::fake()->image('avatar.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();

        $media = $user->fresh()->getFirstMedia(User::PROFILE_PHOTO_COLLECTION);

        $this->assertNotNull($media);
        $this->assertSame('profile_photo', $media->collection_name);
        $this->assertSame('filament_public', $media->disk);
    }

    public function test_a_new_photo_replaces_the_previous_one_without_accumulating(): void
    {
        $user = $this->student();
        Sanctum::actingAs($user);

        $this->patch($this->url, [
            'profile_photo' => UploadedFile::fake()->image('first.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();

        $first = $user->fresh()->getFirstMedia(User::PROFILE_PHOTO_COLLECTION);

        $second = $this->patch($this->url, [
            'profile_photo' => UploadedFile::fake()->image('second.jpg'),
        ], ['Accept' => 'application/json'])->assertOk()->json('data.profile_photo');

        $user->refresh();

        $this->assertSame(1, $user->getMedia(User::PROFILE_PHOTO_COLLECTION)->count());
        $this->assertStringContainsString('second.jpg', (string) $second);
        $this->assertDatabaseMissing('media', ['id' => $first->id]);
    }

    public function test_profile_photo_is_unchanged_when_no_file_is_submitted(): void
    {
        $user = $this->student();
        Sanctum::actingAs($user);

        $this->patch($this->url, [
            'profile_photo' => UploadedFile::fake()->image('avatar.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();

        $before = $user->fresh()->getFirstMedia(User::PROFILE_PHOTO_COLLECTION);

        $this->patchJson($this->url, ['institution' => 'Cairo University'])
            ->assertOk()
            ->assertJsonPath('data.institution', 'Cairo University');

        $after = $user->fresh()->getFirstMedia(User::PROFILE_PHOTO_COLLECTION);

        $this->assertNotNull($after);
        $this->assertSame($before->id, $after->id);
        $this->assertSame(1, $user->fresh()->getMedia(User::PROFILE_PHOTO_COLLECTION)->count());
    }

    public function test_non_image_upload_is_rejected(): void
    {
        $user = $this->student();
        Sanctum::actingAs($user);

        $this->patch($this->url, [
            'profile_photo' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['profile_photo']);

        $this->assertSame(0, $user->fresh()->getMedia(User::PROFILE_PHOTO_COLLECTION)->count());
    }

    public function test_disallowed_image_mime_type_is_rejected(): void
    {
        $user = $this->student();
        Sanctum::actingAs($user);

        // gif is a valid image but is not in config('media.image_mime_types').
        $this->patch($this->url, [
            'profile_photo' => UploadedFile::fake()->image('animation.gif'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['profile_photo']);

        $this->assertSame(0, $user->fresh()->getMedia(User::PROFILE_PHOTO_COLLECTION)->count());
    }

    public function test_oversized_image_is_rejected_using_the_project_limit(): void
    {
        $user = $this->student();
        Sanctum::actingAs($user);

        $limitKb = (int) config('media.max_image_size_kb');

        $this->patch($this->url, [
            'profile_photo' => UploadedFile::fake()->image('huge.jpg')->size($limitKb + 1),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['profile_photo']);

        $this->assertSame(0, $user->fresh()->getMedia(User::PROFILE_PHOTO_COLLECTION)->count());
    }

    public function test_image_at_the_project_limit_is_accepted(): void
    {
        $user = $this->student();
        Sanctum::actingAs($user);

        $limitKb = (int) config('media.max_image_size_kb');

        $this->patch($this->url, [
            'profile_photo' => UploadedFile::fake()->image('at-limit.jpg')->size($limitKb),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame(1, $user->fresh()->getMedia(User::PROFILE_PHOTO_COLLECTION)->count());
    }

    public function test_photo_upload_accompanies_other_multipart_fields(): void
    {
        $egypt = $this->createCountry('EG', 'Egypt', '20');
        $user = $this->student();
        Sanctum::actingAs($user);

        $response = $this->patch($this->url, [
            'full_name' => 'Yousra Ahmed',
            'phone' => '+201234567890',
            'country_id' => $egypt->id,
            'institution' => 'Cairo University',
            'field_of_study' => 'Medicine',
            'profile_photo' => UploadedFile::fake()->image('avatar.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();

        $response
            ->assertJsonPath('data.full_name', 'Yousra Ahmed')
            ->assertJsonPath('data.phone', '+201234567890')
            ->assertJsonPath('data.phone_verified', false)
            ->assertJsonPath('data.country.id', $egypt->id)
            ->assertJsonPath('data.institution', 'Cairo University')
            ->assertJsonPath('data.field_of_study', 'Medicine');

        $this->assertIsString($response->json('data.profile_photo'));
    }

    public function test_profile_photo_response_does_not_expose_internal_media_ids(): void
    {
        $user = $this->student();
        Sanctum::actingAs($user);

        $this->patch($this->url, [
            'profile_photo' => UploadedFile::fake()->image('avatar.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();

        $data = $this->getJson($this->url)->assertOk()->json('data');

        $this->assertIsString($data['profile_photo']);
        $this->assertArrayNotHasKey('media', $data);
        $this->assertArrayNotHasKey('profile_photo_id', $data);
    }

    public function test_photo_upload_requires_authentication(): void
    {
        $this->patch($this->url, [
            'profile_photo' => UploadedFile::fake()->image('avatar.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(401);
    }
}
