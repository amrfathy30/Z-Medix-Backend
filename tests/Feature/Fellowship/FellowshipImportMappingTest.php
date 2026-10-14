<?php

namespace Tests\Feature\Fellowship;

use App\Enums\FellowshipImportMappingTarget;
use App\Exceptions\Fellowship\FellowshipImportException;
use App\Support\Fellowship\ImportHeaderMap;

/**
 * The mapping contract: which columns an import must have behind it before it
 * may be confirmed, and which mappings cannot be applied coherently at all.
 *
 * Column names are the admin's, so none of this depends on what a column is
 * called — only on what it was mapped to.
 */
class FellowshipImportMappingTest extends FellowshipTestCase
{
    /**
     * @return array<string, array<string, mixed>>
     */
    private function completeEntries(): array
    {
        return [
            'Stem' => FellowshipImportMappingTarget::QuestionText->value,
            'A' => FellowshipImportMappingTarget::AnswerOption->value,
            'B' => FellowshipImportMappingTarget::AnswerOption->value,
            'Key' => FellowshipImportMappingTarget::CorrectAnswer->value,
            'Level' => FellowshipImportMappingTarget::Difficulty->value,
            'Area' => FellowshipImportMappingTarget::PerformanceTopic->value,
        ];
    }

    public function test_a_complete_mapping_is_accepted_whatever_the_columns_are_called(): void
    {
        $map = ImportHeaderMap::fromArray($this->completeEntries());

        $this->assertTrue($map->isComplete());
        $this->assertSame([], $map->missingRequiredTargets());

        $map->assertUsable();
    }

    public function test_every_required_field_must_be_mapped(): void
    {
        foreach (['Stem', 'Key', 'Level', 'Area'] as $header) {
            $entries = $this->completeEntries();
            unset($entries[$header]);

            $map = ImportHeaderMap::fromArray($entries);

            $this->assertFalse($map->isComplete(), "Mapping without [{$header}] should be incomplete.");
        }
    }

    public function test_a_single_answer_option_column_is_not_enough(): void
    {
        $entries = $this->completeEntries();
        unset($entries['B']);

        $map = ImportHeaderMap::fromArray($entries);

        $this->assertFalse($map->isComplete());
        $this->assertContains(FellowshipImportMappingTarget::AnswerOption, $map->missingRequiredTargets());
    }

    public function test_an_incomplete_mapping_blocks_the_import(): void
    {
        $entries = $this->completeEntries();
        unset($entries['Area']);

        $this->expectException(FellowshipImportException::class);

        ImportHeaderMap::fromArray($entries)->assertUsable();
    }

    public function test_explanation_and_dynamic_filters_are_optional(): void
    {
        $map = ImportHeaderMap::fromArray($this->completeEntries());

        $this->assertTrue($map->isComplete());
        $this->assertFalse($map->has(FellowshipImportMappingTarget::Explanation));
        $this->assertSame([], $map->dynamicFilterColumns());
    }

    public function test_two_columns_cannot_feed_the_same_new_filter(): void
    {
        $map = ImportHeaderMap::fromArray([
            ...$this->completeEntries(),
            'System' => ['target' => FellowshipImportMappingTarget::NewFilter->value, 'filter_name' => 'System'],
            'Body System' => ['target' => FellowshipImportMappingTarget::NewFilter->value, 'filter_name' => 'system'],
        ]);

        $this->expectException(FellowshipImportException::class);

        $map->assertUsable();
    }

    public function test_a_new_filter_column_needs_a_name(): void
    {
        $map = ImportHeaderMap::fromArray([
            ...$this->completeEntries(),
            'System' => ['target' => FellowshipImportMappingTarget::NewFilter->value],
        ]);

        $this->expectException(FellowshipImportException::class);

        $map->assertUsable();
    }

    public function test_answer_option_columns_keep_the_file_order(): void
    {
        $map = ImportHeaderMap::fromArray([
            'Option C' => FellowshipImportMappingTarget::AnswerOption->value,
            'Option A' => FellowshipImportMappingTarget::AnswerOption->value,
            'Option B' => FellowshipImportMappingTarget::AnswerOption->value,
        ]);

        $this->assertSame(
            ['Option C', 'Option A', 'Option B'],
            $map->headersFor(FellowshipImportMappingTarget::AnswerOption),
        );
    }

    public function test_an_ignored_column_is_mapped_to_nothing(): void
    {
        $map = ImportHeaderMap::fromArray([
            ...$this->completeEntries(),
            'Internal Notes' => FellowshipImportMappingTarget::Ignore->value,
        ]);

        $map->assertUsable();

        $this->assertSame([], $map->dynamicFilterColumns());
    }
}
