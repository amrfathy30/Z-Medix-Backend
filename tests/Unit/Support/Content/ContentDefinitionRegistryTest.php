<?php

namespace Tests\Unit\Support\Content;

use App\Support\Content\Definitions\ContentDefinitionRegistry;
use App\Support\Content\Definitions\ContentItemDefinition;
use App\Support\Content\Definitions\ContentSectionDefinition;
use App\Support\Content\Definitions\Exceptions\MissingContentDefinitionException;
use App\Support\Content\Definitions\PageContentDefinition;
use App\Support\Content\Enums\ContentInputType;
use PHPUnit\Framework\TestCase;

class ContentDefinitionRegistryTest extends TestCase
{
    private function registryWithAboutPage(): ContentDefinitionRegistry
    {
        $hero = ContentSectionDefinition::make([
            'section_key' => 'hero',
            'label_en' => 'Hero',
            'label_ar' => 'القسم الرئيسي',
            'items' => [
                ContentItemDefinition::make([
                    'key' => 'title',
                    'type' => ContentInputType::ShortText,
                    'label_en' => 'Title',
                    'label_ar' => 'العنوان',
                    'translatable' => true,
                ]),
            ],
        ]);

        $page = PageContentDefinition::make('about', 'About', 'حول', [$hero]);

        return (new ContentDefinitionRegistry)->register($page);
    }

    public function test_it_resolves_a_registered_page(): void
    {
        $registry = $this->registryWithAboutPage();

        $page = $registry->forPage('about');

        $this->assertSame('about', $page->key);
        $this->assertTrue($registry->has('about'));
    }

    public function test_it_resolves_a_registered_section(): void
    {
        $registry = $this->registryWithAboutPage();

        $section = $registry->forSection('about', 'hero');

        $this->assertSame('hero', $section->sectionKey);
        $this->assertTrue($registry->hasSection('about', 'hero'));
    }

    public function test_missing_page_throws_developer_error(): void
    {
        $registry = $this->registryWithAboutPage();

        $this->expectException(MissingContentDefinitionException::class);
        $this->expectExceptionMessage('Missing content definition for page contact.');

        $registry->forPage('contact');
    }

    public function test_missing_section_throws_developer_error_with_page_and_section(): void
    {
        $registry = $this->registryWithAboutPage();

        $this->expectException(MissingContentDefinitionException::class);
        $this->expectExceptionMessage('Missing content definition for page about, section unknown.');

        $registry->forSection('about', 'unknown');
    }

    public function test_unregistered_page_section_lookup_throws_page_level_error(): void
    {
        $registry = $this->registryWithAboutPage();

        $this->expectException(MissingContentDefinitionException::class);
        $this->expectExceptionMessage('Missing content definition for page contact.');

        $registry->forSection('contact', 'hero');
    }
}
