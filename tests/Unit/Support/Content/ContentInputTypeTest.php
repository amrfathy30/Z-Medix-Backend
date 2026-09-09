<?php

namespace Tests\Unit\Support\Content;

use App\Support\Content\Enums\ContentInputType;
use PHPUnit\Framework\TestCase;

class ContentInputTypeTest extends TestCase
{
    public function test_all_required_cases_exist(): void
    {
        $expected = [
            'short_text', 'long_text', 'rich_text', 'url', 'email', 'password',
            'phone', 'integer', 'decimal', 'money', 'percentage', 'boolean',
            'checkbox_list', 'radio', 'select', 'multi_select', 'image',
            'multi_image', 'file', 'video_upload', 'video_url', 'audio_upload',
            'date', 'datetime', 'time', 'color', 'icon', 'cta', 'repeater',
            'key_value', 'hidden',
        ];

        $actual = array_map(fn (ContentInputType $case): string => $case->value, ContentInputType::cases());

        foreach ($expected as $value) {
            $this->assertContains($value, $actual, "Missing ContentInputType case: {$value}");
        }

        $this->assertCount(count($expected), $actual);
    }

    public function test_can_resolve_case_from_value(): void
    {
        $this->assertSame(ContentInputType::RichText, ContentInputType::from('rich_text'));
    }
}
