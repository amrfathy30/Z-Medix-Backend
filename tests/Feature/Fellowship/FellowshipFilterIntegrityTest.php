<?php

namespace Tests\Feature\Fellowship;

use App\Exceptions\Fellowship\FellowshipImportException;
use App\Models\FellowshipQuestion;
use App\Services\Fellowship\FellowshipFilterService;
use App\Support\Fellowship\FellowshipQuestionFormFields;
use App\Support\Fellowship\SavesQuestionFilterValues;
use Illuminate\Database\QueryException;

/**
 * Filters are per-Fellowship, and a question answers only to its own
 * Fellowship's classifications.
 *
 * Also pins down "one question, at most one value per filter", which the pivot's
 * unique index enforces rather than the application remembering to.
 */
class FellowshipFilterIntegrityTest extends FellowshipTestCase
{
    use SavesQuestionFilterValues;

    public function test_a_question_may_hold_at_most_one_value_per_filter(): void
    {
        $fellowship = $this->fellowship();
        $question = $this->question($fellowship);
        $system = $this->dynamicFilter($fellowship, 'System', ['Cardiovascular', 'Respiratory']);

        $this->tagQuestion($question, $system, 'Cardiovascular');

        $this->expectException(QueryException::class);

        $question->filterValues()->create([
            'fellowship_filter_id' => $system->getKey(),
            'fellowship_filter_value_id' => $system->values()->where('value', 'Respiratory')->value('id'),
        ]);
    }

    public function test_changing_a_questions_value_for_a_filter_replaces_it(): void
    {
        $fellowship = $this->fellowship();
        $question = $this->question($fellowship);
        $system = $this->dynamicFilter($fellowship, 'System', ['Cardiovascular', 'Respiratory']);

        $this->tagQuestion($question, $system, 'Cardiovascular');
        $this->tagQuestion($question, $system, 'Respiratory');

        $this->assertSame(1, $question->refresh()->filterValues()->count());
        $this->assertSame('Respiratory', $question->valueForFilter($system->getKey())?->value->value);
    }

    public function test_a_question_may_hold_one_value_in_each_of_several_filters(): void
    {
        $fellowship = $this->fellowship();
        $question = $this->question($fellowship);

        $system = $this->dynamicFilter($fellowship, 'System', ['Cardiovascular']);
        $source = $this->dynamicFilter($fellowship, 'Source', ['MRCP']);

        $this->tagQuestion($question, $system, 'Cardiovascular');
        $this->tagQuestion($question, $source, 'MRCP');

        $this->assertSame(2, $question->refresh()->filterValues()->count());
    }

    public function test_two_fellowships_may_both_have_a_filter_of_the_same_name(): void
    {
        $first = $this->fellowship('First');
        $second = $this->fellowship('Second');

        $firstSystem = $this->dynamicFilter($first, 'System', ['Cardiovascular']);
        $secondSystem = $this->dynamicFilter($second, 'System', ['Cardiovascular']);

        $this->assertNotSame($firstSystem->getKey(), $secondSystem->getKey());
        $this->assertSame(1, $first->dynamicFilters()->count());
        $this->assertSame(1, $second->dynamicFilters()->count());
    }

    public function test_one_fellowship_cannot_hold_two_filters_of_the_same_name(): void
    {
        $fellowship = $this->fellowship();
        $this->dynamicFilter($fellowship, 'System', ['Cardiovascular']);

        $this->expectException(QueryException::class);

        $fellowship->filters()->create(['name' => 'System']);
    }

    public function test_a_filter_cannot_hold_the_same_value_twice(): void
    {
        $fellowship = $this->fellowship();
        $system = $this->dynamicFilter($fellowship, 'System', ['Cardiovascular']);

        $this->expectException(QueryException::class);

        $system->values()->create(['value' => 'Cardiovascular']);
    }

    public function test_a_filter_from_another_fellowship_is_refused(): void
    {
        $fellowship = $this->fellowship('First');
        $other = $this->fellowship('Second');
        $otherFilter = $this->dynamicFilter($other, 'System', ['Cardiovascular']);

        $this->expectException(FellowshipImportException::class);

        app(FellowshipFilterService::class)->assertFilterBelongsTo($fellowship, $otherFilter);
    }

    public function test_a_filter_value_from_another_fellowship_is_refused(): void
    {
        $fellowship = $this->fellowship('First');
        $other = $this->fellowship('Second');
        $otherValue = $this->dynamicFilter($other, 'System', ['Cardiovascular'])->values()->sole();

        $this->expectException(FellowshipImportException::class);

        app(FellowshipFilterService::class)->assertValueBelongsTo($fellowship, $otherValue);
    }

    public function test_the_admin_form_will_not_attach_another_fellowships_filter_value(): void
    {
        $fellowship = $this->fellowship('First');
        $question = $this->question($fellowship);

        $other = $this->fellowship('Second');
        $otherFilter = $this->dynamicFilter($other, 'System', ['Cardiovascular']);

        $this->saveFilterValues($question, [
            $otherFilter->getKey() => $otherFilter->values()->value('id'),
        ]);

        $this->assertSame(0, $question->refresh()->filterValues()->count());
    }

    public function test_a_value_is_reused_across_imports_rather_than_duplicated(): void
    {
        $fellowship = $this->fellowship();
        $filters = app(FellowshipFilterService::class);

        $filter = $filters->resolveFilter($fellowship, 'System');

        $first = $filters->resolveValue($filter, 'Cardiovascular');
        $second = $filters->resolveValue($filter, '  cardiovascular  ');

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, $filter->values()->count());
    }

    public function test_a_filter_name_is_matched_regardless_of_case_and_spacing(): void
    {
        $fellowship = $this->fellowship();
        $filters = app(FellowshipFilterService::class);

        $created = $filters->resolveFilter($fellowship, 'Body System');
        $found = $filters->findFilterByName($fellowship, '  body   system ');

        $this->assertSame($created->getKey(), $found?->getKey());
    }

    public function test_a_topic_value_a_question_still_uses_cannot_be_deleted(): void
    {
        $fellowship = $this->fellowship();
        $question = $this->question($fellowship, topic: 'Cardiology');

        // Nulling it would silently unclassify the question, and every question
        // must carry exactly one topic — so the database refuses the delete.
        $this->expectException(QueryException::class);

        $question->performanceTopic->delete();
    }

    public function test_a_topic_value_may_be_deleted_once_no_question_uses_it(): void
    {
        $fellowship = $this->fellowship();
        $question = $this->question($fellowship, topic: 'Cardiology');
        $oldTopic = $question->performanceTopic;

        // Re-file the question, which is what the admin UI tells them to do.
        $question->update(['performance_filter_value_id' => $this->topicValue($fellowship, 'Neurology')->getKey()]);

        $oldTopic->delete();

        $this->assertDatabaseMissing('fellowship_filter_values', ['id' => $oldTopic->getKey()]);
        $this->assertNotNull(FellowshipQuestion::query()->find($question->getKey()));
        $this->assertSame('Neurology', $question->refresh()->performanceTopic?->value);
    }

    public function test_a_soft_deleted_question_still_holds_its_topic_value(): void
    {
        $fellowship = $this->fellowship();
        $question = $this->question($fellowship, topic: 'Cardiology');
        $topic = $question->performanceTopic;

        $question->delete();

        // A soft-deleted question may be restored, so its classification must
        // still be there when it is.
        $this->expectException(QueryException::class);

        $topic->delete();
    }

    public function test_a_performance_topic_from_another_filter_is_refused(): void
    {
        $fellowship = $this->fellowship();
        $system = $this->dynamicFilter($fellowship, 'System', ['Cardiovascular']);

        $this->expectException(FellowshipImportException::class);

        // A value of this fellowship, but of the wrong filter.
        app(FellowshipFilterService::class)->assertPerformanceTopic(
            $fellowship,
            $system->values()->value('id'),
        );
    }

    public function test_a_missing_performance_topic_is_refused(): void
    {
        $this->expectException(FellowshipImportException::class);

        app(FellowshipFilterService::class)->assertPerformanceTopic($this->fellowship(), null);
    }

    public function test_a_value_of_another_filter_is_refused_for_that_filter(): void
    {
        $fellowship = $this->fellowship();
        $system = $this->dynamicFilter($fellowship, 'System', ['Cardiovascular']);
        $source = $this->dynamicFilter($fellowship, 'Source', ['MRCP']);

        $this->expectException(FellowshipImportException::class);

        app(FellowshipFilterService::class)->assertValueBelongsToFilter(
            $system,
            (int) $source->values()->value('id'),
        );
    }

    public function test_the_admin_form_will_not_attach_a_value_from_a_different_filter(): void
    {
        $fellowship = $this->fellowship();
        $question = $this->question($fellowship);

        $system = $this->dynamicFilter($fellowship, 'System', ['Cardiovascular']);
        $source = $this->dynamicFilter($fellowship, 'Source', ['MRCP']);

        // An owned filter id paired with another filter's value.
        $this->saveFilterValues($question, [
            $system->getKey() => $source->values()->value('id'),
        ]);

        $this->assertSame(
            0,
            $question->refresh()->filterValues()->count(),
            'A value that does not belong to the named filter must be refused.',
        );
    }

    public function test_the_filter_field_prefix_round_trips_a_questions_values(): void
    {
        $fellowship = $this->fellowship();
        $question = $this->question($fellowship);
        $system = $this->dynamicFilter($fellowship, 'System', ['Cardiovascular']);

        $this->tagQuestion($question, $system, 'Cardiovascular');

        $state = FellowshipQuestionFormFields::filterStateFor($question->refresh());

        $this->assertSame(
            [FellowshipQuestionFormFields::FILTER_FIELD_PREFIX.$system->getKey() => $system->values()->value('id')],
            $state,
        );
    }
}
