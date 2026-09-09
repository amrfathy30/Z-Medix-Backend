<?php

namespace Tests\Feature\Cms;

use App\Models\PageSection;
use App\Services\Media\MediaUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class MediaUploadServiceTest extends TestCase
{
    use RefreshDatabase;

    private MediaUploadService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('filament_public');

        $this->service = new MediaUploadService;
    }

    public function test_upload_stores_file_in_collection(): void
    {
        $section = PageSection::factory()->create();
        $file = UploadedFile::fake()->image('banner.jpg');

        $media = $this->service->upload($section, $file, 'banner');

        $this->assertSame('banner', $media->collection_name);
        $this->assertSame(1, $section->getMedia('banner')->count());
    }

    public function test_upload_attaches_custom_properties(): void
    {
        $section = PageSection::factory()->create();
        $file = UploadedFile::fake()->image('image.jpg');

        $media = $this->service->upload($section, $file, 'images', ['alt' => 'Hero image']);

        $this->assertSame('Hero image', $media->getCustomProperty('alt'));
    }

    public function test_upload_multiple_stores_all_files(): void
    {
        $section = PageSection::factory()->create();
        $files = [
            UploadedFile::fake()->image('one.jpg'),
            UploadedFile::fake()->image('two.jpg'),
        ];

        $media = $this->service->uploadMultiple($section, $files, 'images');

        $this->assertCount(2, $media);
        $this->assertSame(2, $section->getMedia('images')->count());
    }

    public function test_replace_swaps_the_existing_media_for_the_new_file(): void
    {
        $section = PageSection::factory()->create();

        $old = $this->service->upload($section, UploadedFile::fake()->image('old.jpg'), 'banner');
        $this->assertSame(1, $section->getMedia('banner')->count());

        $newMedia = $this->service->replace($section, UploadedFile::fake()->image('new.jpg'), 'banner');

        $section->refresh();
        $this->assertSame(1, $section->getMedia('banner')->count());
        $this->assertSame($newMedia->id, $section->getFirstMedia('banner')->id);
        $this->assertDatabaseMissing('media', ['id' => $old->id]);
    }

    public function test_replace_preserves_existing_media_when_new_upload_fails(): void
    {
        $section = PageSection::factory()->create();
        $old = $this->service->upload($section, UploadedFile::fake()->image('old.jpg'), 'banner');

        try {
            $this->service->replace($section, '/tmp/this-file-does-not-exist.jpg', 'banner');
            $this->fail('Replacing with an unreadable file should throw.');
        } catch (\Throwable) {
        }

        $this->assertSame($old->id, $section->fresh()->getFirstMedia('banner')?->id);
    }

    public function test_replace_refreshes_a_loaded_media_relation(): void
    {
        $section = PageSection::factory()->create();
        $this->service->upload($section, UploadedFile::fake()->image('old.jpg'), 'banner');
        $section->load('media');

        $new = $this->service->replace($section, UploadedFile::fake()->image('new.jpg'), 'banner');

        $this->assertInstanceOf(Media::class, $new);
        $this->assertSame($new->id, $section->getFirstMedia('banner')?->id);
    }

    public function test_clear_collection_removes_all_media(): void
    {
        $section = PageSection::factory()->create();
        $this->service->uploadMultiple($section, [
            UploadedFile::fake()->image('a.jpg'),
            UploadedFile::fake()->image('b.jpg'),
        ], 'images');

        $this->service->clearCollection($section, 'images');

        $this->assertSame(0, $section->getMedia('images')->count());
    }

    public function test_delete_removes_specific_media_record(): void
    {
        $section = PageSection::factory()->create();
        $media = $this->service->upload($section, UploadedFile::fake()->image('a.jpg'), 'images');

        $result = $this->service->delete($media);

        $this->assertTrue($result);
        $this->assertSame(0, $section->refresh()->getMedia('images')->count());
    }

    public function test_delete_from_collection_removes_by_id(): void
    {
        $section = PageSection::factory()->create();
        $media = $this->service->upload($section, UploadedFile::fake()->image('a.jpg'), 'images');

        $result = $this->service->deleteFromCollection($section, 'images', $media->id);

        $this->assertTrue($result);
        $this->assertSame(0, $section->refresh()->getMedia('images')->count());
    }

    public function test_delete_from_collection_returns_false_when_not_found(): void
    {
        $section = PageSection::factory()->create();

        $result = $this->service->deleteFromCollection($section, 'images', 999999);

        $this->assertFalse($result);
    }

    public function test_get_url_returns_null_when_collection_empty(): void
    {
        $section = PageSection::factory()->create();

        $this->assertNull($this->service->getUrl($section, 'banner'));
    }

    public function test_get_url_returns_string_when_media_exists(): void
    {
        $section = PageSection::factory()->create();
        $this->service->upload($section, UploadedFile::fake()->image('banner.jpg'), 'banner');

        $this->assertIsString($this->service->getUrl($section, 'banner'));
    }

    public function test_get_urls_returns_url_for_each_media_item(): void
    {
        $section = PageSection::factory()->create();
        $this->service->uploadMultiple($section, [
            UploadedFile::fake()->image('a.jpg'),
            UploadedFile::fake()->image('b.jpg'),
        ], 'images');

        $urls = $this->service->getUrls($section, 'images');

        $this->assertCount(2, $urls);
        $this->assertContainsOnly('string', $urls);
    }

    public function test_to_payload_returns_expected_shape(): void
    {
        $section = PageSection::factory()->create();
        $media = $this->service->upload($section, UploadedFile::fake()->image('banner.jpg'), 'banner');

        $payload = $this->service->toPayload($media);

        $this->assertSame($media->id, $payload['id']);
        $this->assertSame('banner', $payload['collection']);
        $this->assertArrayHasKey('url', $payload);
        $this->assertArrayHasKey('mime_type', $payload);
        $this->assertArrayHasKey('size', $payload);
    }

    public function test_collection_to_payload_returns_array_for_each_media_item(): void
    {
        $section = PageSection::factory()->create();
        $this->service->uploadMultiple($section, [
            UploadedFile::fake()->image('a.jpg'),
            UploadedFile::fake()->image('b.jpg'),
        ], 'images');

        $payloads = $this->service->collectionToPayload($section, 'images');

        $this->assertCount(2, $payloads);
        $this->assertArrayHasKey('url', $payloads[0]);
    }
}
