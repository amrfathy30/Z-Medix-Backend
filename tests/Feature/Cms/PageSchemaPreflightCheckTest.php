<?php

namespace Tests\Feature\Cms;

use App\Models\Page;
use App\Support\Content\Migrations\PageSchemaPreflightCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PageSchemaPreflightCheck is generic — it must not hardcode any specific
 * page keys or section keys. Callers pass the page keys/section pairs they
 * care about; the three Website Content migrations pass privacy-policy and
 * terms-and-conditions to reproduce their original Phase 3D/3H/3I behavior.
 */
class PageSchemaPreflightCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_violations_accepts_required_page_keys_and_required_sections(): void
    {
        $violations = PageSchemaPreflightCheck::violations(
            requiredPageKeys: ['privacy-policy', 'terms-and-conditions', 'contact-us'],
            requiredSections: [
                ['page_key' => 'privacy-policy', 'section_key' => 'content'],
                ['page_key' => 'terms-and-conditions', 'section_key' => 'content'],
                ['page_key' => 'contact-us', 'section_key' => 'content'],
            ],
        );

        $this->assertSame([], $violations);
    }

    public function test_does_not_hardcode_privacy_terms_or_content_internally(): void
    {
        // A page named 'privacy-policy' with no 'content' section would have
        // been flagged by the old hardcoded check. With no required
        // sections passed, the generic check must not know about it.
        Page::factory()->create(['key' => 'privacy-policy']);

        $this->assertSame([], PageSchemaPreflightCheck::violations());
        $this->assertTrue(PageSchemaPreflightCheck::isClean());
    }

    public function test_flags_missing_section_for_any_caller_supplied_page_key(): void
    {
        Page::factory()->create(['key' => 'contact-us']);

        $violations = PageSchemaPreflightCheck::violations(
            requiredPageKeys: ['contact-us'],
            requiredSections: [['page_key' => 'contact-us', 'section_key' => 'content']],
        );

        $this->assertNotEmpty($violations);
        $this->assertStringContainsString("Page 'contact-us'", implode(' ', $violations));
    }

    public function test_does_not_require_supplied_page_keys_to_already_exist(): void
    {
        // A brand-new install has no pages rows at all yet — nothing to
        // lose, so the check must stay clean even when keys are supplied.
        $violations = PageSchemaPreflightCheck::violations(
            requiredPageKeys: ['privacy-policy', 'terms-and-conditions', 'contact-us'],
            requiredSections: [
                ['page_key' => 'privacy-policy', 'section_key' => 'content'],
                ['page_key' => 'terms-and-conditions', 'section_key' => 'content'],
                ['page_key' => 'contact-us', 'section_key' => 'content'],
            ],
        );

        $this->assertSame([], $violations);
    }
}
