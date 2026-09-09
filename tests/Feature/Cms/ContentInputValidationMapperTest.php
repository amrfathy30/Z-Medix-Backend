<?php

namespace Tests\Feature\Cms;

use App\Support\Content\Enums\ContentInputType;
use App\Support\Content\Validation\ContentInputValidationMapper;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ContentInputValidationMapperTest extends TestCase
{
    private ContentInputValidationMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new ContentInputValidationMapper;
    }

    public function test_email_rules_are_nullable_email(): void
    {
        $this->assertSame(['nullable', 'email'], $this->mapper->rulesFor(ContentInputType::Email));
    }

    public function test_url_and_video_url_rules_are_nullable_url(): void
    {
        $this->assertSame(['nullable', 'url'], $this->mapper->rulesFor(ContentInputType::Url));
        $this->assertSame(['nullable', 'url'], $this->mapper->rulesFor(ContentInputType::VideoUrl));
    }

    public function test_integer_rules(): void
    {
        $this->assertSame(['nullable', 'integer'], $this->mapper->rulesFor(ContentInputType::Integer));
    }

    public function test_decimal_money_percentage_are_nullable_numeric(): void
    {
        $this->assertSame(['nullable', 'numeric'], $this->mapper->rulesFor(ContentInputType::Decimal));
        $this->assertSame(['nullable', 'numeric'], $this->mapper->rulesFor(ContentInputType::Money));
        $this->assertSame(['nullable', 'numeric'], $this->mapper->rulesFor(ContentInputType::Percentage));
    }

    public function test_short_text_rules(): void
    {
        $this->assertSame(['nullable', 'string', 'max:255'], $this->mapper->rulesFor(ContentInputType::ShortText));
    }

    public function test_boolean_rules(): void
    {
        $this->assertSame(['nullable', 'boolean'], $this->mapper->rulesFor(ContentInputType::Boolean));
    }

    public function test_image_rules_include_image_and_config_driven_constraints(): void
    {
        $rules = $this->mapper->rulesFor(ContentInputType::Image);

        $this->assertContains('nullable', $rules);
        $this->assertContains('image', $rules);
        $this->assertTrue((bool) array_filter($rules, fn (string $rule): bool => str_starts_with($rule, 'mimetypes:')));
        $this->assertTrue((bool) array_filter($rules, fn (string $rule): bool => str_starts_with($rule, 'max:')));
    }

    public function test_video_upload_rules_use_video_mime_config(): void
    {
        $rules = $this->mapper->rulesFor(ContentInputType::VideoUpload);

        $this->assertContains('nullable', $rules);
        $this->assertContains('file', $rules);
        $mimeRule = array_values(array_filter($rules, fn (string $rule): bool => str_starts_with($rule, 'mimetypes:')))[0] ?? null;
        $this->assertNotNull($mimeRule);
        $this->assertStringContainsString('video/mp4', $mimeRule);
    }

    public function test_audio_upload_rules_use_audio_mime_config(): void
    {
        $rules = $this->mapper->rulesFor(ContentInputType::AudioUpload);

        $mimeRule = array_values(array_filter($rules, fn (string $rule): bool => str_starts_with($rule, 'mimetypes:')))[0] ?? null;
        $this->assertNotNull($mimeRule);
        $this->assertStringContainsString('audio/', $mimeRule);
    }

    public function test_no_required_rule_is_ever_present(): void
    {
        foreach (ContentInputType::cases() as $type) {
            $this->assertNotContains('required', $this->mapper->rulesFor($type), "Type {$type->value} must not declare required");
        }
    }

    public function test_rich_text_rules_accept_tiptap_array_state_and_reject_html_state(): void
    {
        $rules = ['body' => $this->mapper->rulesFor(ContentInputType::RichText)];
        $state = ['body' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]]];

        $this->assertSame(['nullable', 'array'], $rules['body']);
        $this->assertTrue(Validator::make($state, $rules)->passes());
        $this->assertTrue(Validator::make(['body' => null], $rules)->passes());
        $this->assertFalse(Validator::make(['body' => '<p>Hello</p>'], $rules)->passes());
        $this->assertSame(['nullable', 'string'], $this->mapper->rulesFor(ContentInputType::LongText));
    }
}
