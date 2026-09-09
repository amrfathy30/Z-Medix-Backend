<?php

namespace Tests\Feature\Media;

use App\Models\PageSection;
use App\Services\Media\MediaUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminPreviewOriginTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_disks_share_a_root_but_have_different_url_audiences(): void
    {
        $this->assertSame(config('filesystems.disks.public.root'), config('filesystems.disks.filament_public.root'));
        $this->assertStringStartsWith('/', (string) config('filesystems.disks.filament_public.url'));
        $this->assertStringNotContainsString('://', (string) config('filesystems.disks.filament_public.url'));
        $this->assertStringContainsString('://', (string) config('filesystems.disks.public.url'));
        $this->assertFileDoesNotExist(config_path('cors.php'));
    }

    public function test_core_section_media_uses_same_origin_preview_disk(): void
    {
        Storage::fake('filament_public');
        $section = PageSection::factory()->create();
        $media = app(MediaUploadService::class)->upload(
            $section,
            UploadedFile::fake()->image('image.jpg'),
            'image',
        );

        $this->assertSame('filament_public', $media->disk);
        $this->assertStringNotContainsString('://', $media->getUrl());
    }
}
