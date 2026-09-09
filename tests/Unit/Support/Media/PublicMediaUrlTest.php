<?php

namespace Tests\Unit\Support\Media;

use App\Support\Media\PublicMediaUrl;
use Tests\TestCase;

class PublicMediaUrlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.url', 'https://api.example.test');
    }

    public function test_relative_urls_become_absolute(): void
    {
        $this->assertSame('https://api.example.test/storage/a.png', PublicMediaUrl::make('/storage/a.png'));
    }

    public function test_absolute_remote_and_protocol_relative_urls_are_unchanged(): void
    {
        $this->assertSame('https://cdn.test/a.png', PublicMediaUrl::make('https://cdn.test/a.png'));
        $this->assertSame('//cdn.test/a.png', PublicMediaUrl::make('//cdn.test/a.png'));
    }

    public function test_empty_media_is_null(): void
    {
        $this->assertNull(PublicMediaUrl::make(null));
        $this->assertNull(PublicMediaUrl::make(''));
    }
}
