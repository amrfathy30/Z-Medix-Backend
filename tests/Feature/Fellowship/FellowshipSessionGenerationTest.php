<?php

namespace Tests\Feature\Fellowship;

use App\Enums\FellowshipQuestionStatusFilter;
use App\Enums\FellowshipSessionStatus;
use App\Enums\QuizDifficulty;
use App\Models\Fellowship;
use App\Models\FellowshipQuestion;
use App\Support\Fellowship\FellowshipSettings;

/**
 * Generating a session: the filter algebra, the availability check, and the
 * snapshots a generated session is made of.
 */
class FellowshipSessionGenerationTest extends StudentFellowshipTestCase
{
    public function test_a_session_is_generated_with_the_requested_number_of_questions(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        foreach (range(1, 5) as $ignored) {
            $this->question($fellowship);
        }

        $response = $this->generate($fellowship, ['question_count' => 3])->assertCreated();

        $response->assertJsonPath('data.question_count', 3);
        $response->assertJsonPath('data.status', FellowshipSessionStatus::InProgress->value);
        $response->assertJsonPath('data.current_position', 1);
        $response->assertJsonCount(3, 'data.questions');
    }

    public function test_generation_is_refused_when_the_bank_cannot_fill_the_request(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $this->question($fellowship);
        $this->question($fellowship);

        $response = $this->generate($fellowship, ['question_count' => 5])
            ->assertStatus(422)
            ->assertJsonPath('code', 'FELLOWSHIP_INSUFFICIENT_QUESTIONS')
            ->assertJsonPath('errors.available_questions', 2);

        $this->assertSame(0, $fellowship->quizSessions()->count(), 'No partial session may be created.');
    }

    public function test_the_availability_endpoint_reports_the_same_pool_generation_draws_from(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $this->question($fellowship, difficulty: QuizDifficulty::Easy);
        $this->question($fellowship, difficulty: QuizDifficulty::Easy);
        $this->question($fellowship, difficulty: QuizDifficulty::Hard);

        $this->postJson($this->fellowshipsUrl."/{$fellowship->id}/available-questions", [
            'title' => 'Preview',
            'question_count' => 2,
            'difficulties' => [QuizDifficulty::Easy->value],
        ])
            ->assertOk()
            ->assertJsonPath('data.available_questions', 2)
            ->assertJsonPath('data.can_generate', true);
    }

    public function test_an_unselected_filter_does_not_restrict_the_pool(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $system = $this->dynamicFilter($fellowship, 'System', ['Cardiovascular', 'Respiratory']);

        $tagged = $this->question($fellowship);
        $this->tagQuestion($tagged, $system, 'Cardiovascular');

        // A question with no value for System at all.
        $this->question($fellowship);

        $this->generate($fellowship, ['question_count' => 2])->assertCreated();
    }

    public function test_values_within_one_filter_widen_the_pool(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $system = $this->dynamicFilter($fellowship, 'System', ['Cardiovascular', 'Respiratory', 'Renal']);

        $this->tagQuestion($this->question($fellowship), $system, 'Cardiovascular');
        $this->tagQuestion($this->question($fellowship), $system, 'Respiratory');
        $this->tagQuestion($this->question($fellowship), $system, 'Renal');

        $cardio = $system->values()->where('value', 'Cardiovascular')->value('id');
        $resp = $system->values()->where('value', 'Respiratory')->value('id');

        $this->postJson($this->fellowshipsUrl."/{$fellowship->id}/available-questions", [
            'title' => 'Preview',
            'question_count' => 1,
            'filters' => [$system->getKey() => [$cardio, $resp]],
        ])
            ->assertOk()
            ->assertJsonPath('data.available_questions', 2);
    }

    public function test_different_filters_must_all_be_satisfied(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $system = $this->dynamicFilter($fellowship, 'System', ['Cardiovascular', 'Respiratory']);
        $source = $this->dynamicFilter($fellowship, 'Source', ['MRCP', 'USMLE']);

        $both = $this->question($fellowship);
        $this->tagQuestion($both, $system, 'Cardiovascular');
        $this->tagQuestion($both, $source, 'MRCP');

        $onlySystem = $this->question($fellowship);
        $this->tagQuestion($onlySystem, $system, 'Cardiovascular');
        $this->tagQuestion($onlySystem, $source, 'USMLE');

        $cardio = $system->values()->where('value', 'Cardiovascular')->value('id');
        $mrcp = $source->values()->where('value', 'MRCP')->value('id');

        $this->postJson($this->fellowshipsUrl."/{$fellowship->id}/available-questions", [
            'title' => 'Preview',
            'question_count' => 1,
            'filters' => [
                $system->getKey() => [$cardio],
                $source->getKey() => [$mrcp],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.available_questions', 1);
    }

    public function test_the_performance_topic_filter_narrows_on_the_questions_own_topic(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $this->question($fellowship, topic: 'Cardiology');
        $this->question($fellowship, topic: 'Cardiology');
        $this->question($fellowship, topic: 'Neurology');

        $filter = $fellowship->performanceFilter;
        $cardio = $filter->values()->where('value', 'Cardiology')->value('id');

        $this->postJson($this->fellowshipsUrl."/{$fellowship->id}/available-questions", [
            'title' => 'Preview',
            'question_count' => 1,
            'filters' => [$filter->getKey() => [$cardio]],
        ])
            ->assertOk()
            ->assertJsonPath('data.available_questions', 2);
    }

    public function test_difficulties_narrow_the_pool(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $this->question($fellowship, difficulty: QuizDifficulty::Easy);
        $this->question($fellowship, difficulty: QuizDifficulty::Medium);
        $this->question($fellowship, difficulty: QuizDifficulty::Hard);

        $this->postJson($this->fellowshipsUrl."/{$fellowship->id}/available-questions", [
            'title' => 'Preview',
            'question_count' => 1,
            'difficulties' => [QuizDifficulty::Easy->value, QuizDifficulty::Hard->value],
        ])
            ->assertOk()
            ->assertJsonPath('data.available_questions', 2);
    }

    public function test_unanswered_excludes_questions_the_student_has_already_confirmed_an_answer_to(): void
    {
        $student = $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $first = $this->question($fellowship);
        $this->question($fellowship);
        $this->question($fellowship);

        // Answer one question through a session, which is the only thing that
        // makes a question "answered".
        $session = $this->generateSession($fellowship, ['question_count' => 3]);
        $this->answerCurrent($session)->assertOk();

        $answeredQuestionId = $session->refresh()->questions()->answered()->value('fellowship_question_id');

        $this->postJson($this->fellowshipsUrl."/{$fellowship->id}/available-questions", [
            'title' => 'Preview',
            'question_count' => 1,
            'question_status' => FellowshipQuestionStatusFilter::Unanswered->value,
        ])
            ->assertOk()
            ->assertJsonPath('data.available_questions', 2);

        $this->postJson($this->fellowshipsUrl."/{$fellowship->id}/available-questions", [
            'title' => 'Preview',
            'question_count' => 1,
            'question_status' => FellowshipQuestionStatusFilter::Answered->value,
        ])
            ->assertOk()
            ->assertJsonPath('data.available_questions', 1);

        $this->assertNotNull($answeredQuestionId);
    }

    public function test_answered_counts_answers_from_any_session_of_the_fellowship(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $this->question($fellowship);
        $this->question($fellowship);

        $first = $this->generateSession($fellowship, ['question_count' => 2]);
        $this->answerAll($first);

        // A second session sees both questions as answered.
        $this->postJson($this->fellowshipsUrl."/{$fellowship->id}/available-questions", [
            'title' => 'Preview',
            'question_count' => 1,
            'question_status' => FellowshipQuestionStatusFilter::Unanswered->value,
        ])
            ->assertOk()
            ->assertJsonPath('data.available_questions', 0);
    }

    public function test_another_students_answers_do_not_affect_this_students_pool(): void
    {
        $other = $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $this->question($fellowship);
        $this->question($fellowship);

        $session = $this->generateSession($fellowship, ['question_count' => 2]);
        $this->answerAll($session);

        // A different student starts from scratch.
        $this->actingAsStudent();

        $this->postJson($this->fellowshipsUrl."/{$fellowship->id}/available-questions", [
            'title' => 'Preview',
            'question_count' => 1,
            'question_status' => FellowshipQuestionStatusFilter::Unanswered->value,
        ])
            ->assertOk()
            ->assertJsonPath('data.available_questions', 2);
    }

    public function test_the_session_snapshots_the_questions_it_generated(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $question = $this->question($fellowship, topic: 'Cardiology', difficulty: QuizDifficulty::Hard, text: 'Which drug?');
        $system = $this->dynamicFilter($fellowship, 'System', ['Cardiovascular']);
        $this->tagQuestion($question, $system, 'Cardiovascular');

        $session = $this->generateSession($fellowship, ['question_count' => 1]);
        $sessionQuestion = $session->questions()->sole();
        $snapshot = $sessionQuestion->snapshot();

        $this->assertSame('Which drug?', $snapshot->questionText);
        $this->assertSame(QuizDifficulty::Hard->value, $snapshot->difficulty);
        $this->assertSame('Cardiology', $snapshot->topicValue());
        $this->assertCount(4, $snapshot->options);
        $this->assertNotNull($snapshot->correctOptionKey());
        $this->assertSame([['filter_id' => $system->getKey(), 'filter_name' => 'System', 'value_id' => $system->values()->value('id'), 'value' => 'Cardiovascular']], $snapshot->filters);

        // And the queryable columns beside it, for analytics.
        $this->assertSame(QuizDifficulty::Hard->value, $sessionQuestion->difficulty);
        $this->assertSame('Cardiology', $sessionQuestion->topic_value);
        $this->assertSame($question->performance_filter_value_id, $sessionQuestion->performance_filter_value_id);
        $this->assertSame($question->getKey(), $sessionQuestion->fellowship_question_id);
    }

    public function test_a_question_is_never_served_twice_in_one_session(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        foreach (range(1, 6) as $ignored) {
            $this->question($fellowship);
        }

        $session = $this->generateSession($fellowship, ['question_count' => 6]);

        $ids = $session->questions()->pluck('fellowship_question_id')->all();

        $this->assertCount(6, array_unique($ids));
        $this->assertSame([1, 2, 3, 4, 5, 6], $session->questions()->pluck('position')->map(fn ($p): int => (int) $p)->all());
    }

    public function test_a_question_without_a_correct_option_is_never_served(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $this->question($fellowship);

        // Hand-broken question: two options, neither correct.
        $broken = FellowshipQuestion::factory()->create([
            'fellowship_id' => $fellowship->getKey(),
            'performance_filter_value_id' => $this->topicValue($fellowship, 'Cardiology')->getKey(),
        ]);
        $broken->options()->createMany([
            ['option_text' => 'A', 'is_correct' => false, 'order' => 1],
            ['option_text' => 'B', 'is_correct' => false, 'order' => 2],
        ]);

        $this->postJson($this->fellowshipsUrl."/{$fellowship->id}/available-questions", [
            'title' => 'Preview',
            'question_count' => 1,
        ])
            ->assertOk()
            ->assertJsonPath('data.available_questions', 1);
    }

    public function test_a_soft_deleted_question_is_never_served(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $this->question($fellowship);
        $this->question($fellowship)->delete();

        $this->generate($fellowship, ['question_count' => 2])
            ->assertStatus(422)
            ->assertJsonPath('errors.available_questions', 1);
    }

    public function test_a_timed_session_snapshots_the_platform_limit(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        FellowshipSettings::setQuestionTimeLimitSeconds(45);

        $this->question($fellowship);

        $session = $this->generateSession($fellowship, ['question_count' => 1, 'timer_enabled' => true]);

        $this->assertTrue($session->timer_enabled);
        $this->assertSame(45, $session->question_time_limit_seconds);

        // Raising the limit afterwards must not re-judge this session.
        FellowshipSettings::setQuestionTimeLimitSeconds(300);

        $this->assertSame(45, $session->refresh()->question_time_limit_seconds);
    }

    public function test_an_untimed_session_carries_no_limit(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();
        $this->question($fellowship);

        $session = $this->generateSession($fellowship, ['question_count' => 1]);

        $this->assertFalse($session->timer_enabled);
        $this->assertNull($session->question_time_limit_seconds);
    }

    public function test_a_student_may_hold_several_sessions_on_one_fellowship(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $this->question($fellowship);
        $this->question($fellowship);

        $this->generate($fellowship, ['title' => 'First', 'question_count' => 2])->assertCreated();
        $this->generate($fellowship, ['title' => 'Second', 'question_count' => 2])->assertCreated();

        $this->assertSame(2, $fellowship->quizSessions()->count());
    }

    public function test_a_title_and_a_question_count_are_required(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();
        $this->question($fellowship);

        $this->postJson($this->fellowshipsUrl."/{$fellowship->id}/sessions", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'question_count']);
    }

    public function test_a_filter_value_from_another_fellowship_is_rejected(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();
        $this->question($fellowship);

        $other = $this->fellowship('Internal Medicine');
        $otherFilter = $this->dynamicFilter($other, 'System', ['Cardiovascular']);

        $this->postJson($this->fellowshipsUrl."/{$fellowship->id}/sessions", [
            'title' => 'Cross fellowship',
            'question_count' => 1,
            'filters' => [$otherFilter->getKey() => [$otherFilter->values()->value('id')]],
        ])->assertStatus(422);
    }

    public function test_an_inactive_fellowship_cannot_be_generated_from(): void
    {
        $this->actingAsStudent();
        $fellowship = Fellowship::factory()->inactive()->withPerformanceFilter()->create();
        $this->question($fellowship);

        $this->generate($fellowship, ['question_count' => 1])
            ->assertStatus(404)
            ->assertJsonPath('code', 'FELLOWSHIP_UNAVAILABLE');
    }

    public function test_generation_requires_authentication(): void
    {
        $fellowship = $this->fellowship();
        $this->question($fellowship);

        $this->generate($fellowship, ['question_count' => 1])->assertUnauthorized();
    }
}
