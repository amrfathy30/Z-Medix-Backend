<?php

namespace Tests\Feature\Fellowship;

use App\Enums\FellowshipQuestionStatusFilter;
use App\Enums\QuizDifficulty;
use App\Models\Fellowship;

/**
 * Counting available questions, and what it does not ask of the student.
 *
 * The endpoint answers a question about filters, so it needs no quiz title and
 * no size: generating a session is what needs those. What it must not do is
 * disagree with generation about which questions are eligible, so the two are
 * checked against each other here as well.
 */
class FellowshipAvailableQuestionsApiTest extends StudentFellowshipTestCase
{
    /**
     * @param  array<string, mixed>  $payload
     */
    private function countAvailable(Fellowship $fellowship, array $payload = [])
    {
        return $this->postJson($this->fellowshipsUrl."/{$fellowship->id}/available-questions", $payload);
    }

    public function test_the_count_needs_no_title(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $this->question($fellowship);
        $this->question($fellowship);

        $this->countAvailable($fellowship, ['question_count' => 2])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.available_questions', 2)
            ->assertJsonPath('data.requested_question_count', 2)
            ->assertJsonPath('data.can_generate', true);
    }

    public function test_a_title_is_still_accepted_and_ignored(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $this->question($fellowship);

        $this->countAvailable($fellowship, ['title' => 'x', 'question_count' => 1])
            ->assertOk()
            ->assertJsonPath('data.available_questions', 1)
            ->assertJsonPath('data.can_generate', true);
    }

    public function test_the_count_needs_no_question_count(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $this->question($fellowship);
        $this->question($fellowship);
        $this->question($fellowship);

        $this->countAvailable($fellowship, [])
            ->assertOk()
            ->assertJsonPath('data.available_questions', 3)
            ->assertJsonPath('data.requested_question_count', null)
            ->assertJsonPath('data.can_generate', true);
    }

    public function test_an_omitted_count_cannot_generate_from_an_empty_pool(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $this->countAvailable($fellowship, [])
            ->assertOk()
            ->assertJsonPath('data.available_questions', 0)
            ->assertJsonPath('data.requested_question_count', null)
            ->assertJsonPath('data.can_generate', false);
    }

    public function test_a_requested_count_beyond_the_pool_cannot_generate(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $this->question($fellowship);
        $this->question($fellowship);

        $this->countAvailable($fellowship, ['question_count' => 5])
            ->assertOk()
            ->assertJsonPath('data.available_questions', 2)
            ->assertJsonPath('data.requested_question_count', 5)
            ->assertJsonPath('data.can_generate', false);
    }

    public function test_the_count_narrows_by_dynamic_filters(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $system = $this->dynamicFilter($fellowship, 'System', ['Cardiovascular', 'Respiratory']);
        $cardiovascular = $system->values()->where('value', 'Cardiovascular')->sole();

        $this->tagQuestion($this->question($fellowship), $system, 'Cardiovascular');
        $this->tagQuestion($this->question($fellowship), $system, 'Respiratory');
        $this->question($fellowship);

        $this->countAvailable($fellowship, ['filters' => [(string) $system->getKey() => [$cardiovascular->getKey()]]])
            ->assertOk()
            ->assertJsonPath('data.available_questions', 1);
    }

    public function test_the_count_narrows_by_the_performance_topic_filter(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $this->question($fellowship, topic: 'Cardiology');
        $this->question($fellowship, topic: 'Cardiology');
        $this->question($fellowship, topic: 'Neurology');

        $filter = $fellowship->performanceFilter;
        $cardiology = $filter->values()->where('value', 'Cardiology')->sole();

        $this->countAvailable($fellowship, ['filters' => [(string) $filter->getKey() => [$cardiology->getKey()]]])
            ->assertOk()
            ->assertJsonPath('data.available_questions', 2);
    }

    public function test_the_count_narrows_by_difficulty(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $this->question($fellowship, difficulty: QuizDifficulty::Easy);
        $this->question($fellowship, difficulty: QuizDifficulty::Hard);
        $this->question($fellowship, difficulty: QuizDifficulty::Hard);

        $this->countAvailable($fellowship, ['difficulties' => [QuizDifficulty::Hard->value]])
            ->assertOk()
            ->assertJsonPath('data.available_questions', 2);
    }

    public function test_the_count_narrows_by_question_status(): void
    {
        $student = $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $this->question($fellowship);
        $this->question($fellowship);
        $this->question($fellowship);

        $session = $this->generateSession($fellowship, ['question_count' => 1]);
        $this->answerCurrent($session)->assertOk();

        $this->countAvailable($fellowship, ['question_status' => FellowshipQuestionStatusFilter::Answered->value])
            ->assertOk()
            ->assertJsonPath('data.available_questions', 1);

        $this->countAvailable($fellowship, ['question_status' => FellowshipQuestionStatusFilter::Unanswered->value])
            ->assertOk()
            ->assertJsonPath('data.available_questions', 2);

        $this->countAvailable($fellowship, ['question_status' => FellowshipQuestionStatusFilter::All->value])
            ->assertOk()
            ->assertJsonPath('data.available_questions', 3);

        $this->assertSame($student->getKey(), $session->user_id);
    }

    public function test_omitted_filters_mean_all(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $system = $this->dynamicFilter($fellowship, 'System', ['Cardiovascular']);
        $this->tagQuestion($this->question($fellowship), $system, 'Cardiovascular');
        $this->question($fellowship);

        $this->countAvailable($fellowship, [])
            ->assertOk()
            ->assertJsonPath('data.available_questions', 2);

        // A filter present but with nothing chosen is the same as untouched.
        $this->countAvailable($fellowship, ['filters' => [(string) $system->getKey() => []]])
            ->assertOk()
            ->assertJsonPath('data.available_questions', 2);
    }

    public function test_a_filter_value_from_another_fellowship_is_refused(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();
        $other = $this->fellowship('Internal Medicine');

        $foreign = $this->topicValue($other, 'Cardiology');

        $this->countAvailable($fellowship, [
            'filters' => [(string) $other->performance_filter_id => [$foreign->getKey()]],
        ])->assertStatus(422)->assertJsonValidationErrors(['filters.'.$other->performance_filter_id.'.0']);
    }

    public function test_an_invalid_difficulty_is_refused(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $this->countAvailable($fellowship, ['difficulties' => ['impossible']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['difficulties.0']);
    }

    public function test_a_question_count_beyond_the_system_ceiling_is_refused(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $ceiling = (int) config('fellowship.session.max_question_count', 200);

        $this->countAvailable($fellowship, ['question_count' => $ceiling + 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['question_count']);
    }

    public function test_an_inactive_fellowship_is_not_counted(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();
        $fellowship->update(['is_active' => false]);

        $this->countAvailable($fellowship, [])
            ->assertStatus(404)
            ->assertJsonPath('code', 'FELLOWSHIP_UNAVAILABLE');
    }

    public function test_counting_requires_an_authenticated_student(): void
    {
        $fellowship = $this->fellowship();

        $this->countAvailable($fellowship, [])->assertStatus(401);
    }

    public function test_generation_still_requires_a_title(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $this->question($fellowship);

        $this->postJson($this->fellowshipsUrl."/{$fellowship->id}/sessions", ['question_count' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title']);

        $this->assertSame(0, $fellowship->quizSessions()->count());
    }

    public function test_generation_still_requires_a_question_count(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $this->question($fellowship);

        $this->postJson($this->fellowshipsUrl."/{$fellowship->id}/sessions", ['title' => 'Morning revision'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['question_count']);

        $this->assertSame(0, $fellowship->quizSessions()->count());
    }

    public function test_the_count_and_generation_agree_on_what_is_eligible(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $system = $this->dynamicFilter($fellowship, 'System', ['Cardiovascular', 'Respiratory']);
        $cardiovascular = $system->values()->where('value', 'Cardiovascular')->sole();

        foreach (range(1, 3) as $ignored) {
            $this->tagQuestion(
                $this->question($fellowship, difficulty: QuizDifficulty::Hard),
                $system,
                'Cardiovascular',
            );
        }

        $this->tagQuestion($this->question($fellowship, difficulty: QuizDifficulty::Easy), $system, 'Cardiovascular');
        $this->tagQuestion($this->question($fellowship, difficulty: QuizDifficulty::Hard), $system, 'Respiratory');

        $criteria = [
            'filters' => [(string) $system->getKey() => [$cardiovascular->getKey()]],
            'difficulties' => [QuizDifficulty::Hard->value],
        ];

        $available = $this->countAvailable($fellowship, $criteria)
            ->assertOk()
            ->json('data.available_questions');

        $this->assertSame(3, $available);

        // Exactly the count reported must be drawable, and one more must not.
        $this->postJson($this->fellowshipsUrl."/{$fellowship->id}/sessions", [
            'title' => 'Hard cardiovascular',
            'question_count' => $available,
            ...$criteria,
        ])->assertCreated()->assertJsonCount($available, 'data.questions');

        $this->postJson($this->fellowshipsUrl."/{$fellowship->id}/sessions", [
            'title' => 'One too many',
            'question_count' => $available + 1,
            ...$criteria,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'FELLOWSHIP_INSUFFICIENT_QUESTIONS')
            ->assertJsonPath('errors.available_questions', $available);
    }

    public function test_the_filters_endpoint_still_reports_its_existing_keys(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $this->dynamicFilter($fellowship, 'System', ['Cardiovascular']);
        $this->question($fellowship, topic: 'Cardiology');

        $response = $this->getJson($this->fellowshipsUrl."/{$fellowship->id}/filters")->assertOk();

        $response->assertJsonStructure([
            'data' => [
                'filters' => [['id', 'name', 'is_performance', 'values' => [['id', 'value']]]],
                'difficulties',
                'question_statuses',
                'total_questions',
                'question_time_limit_seconds',
                'max_question_count',
            ],
        ]);

        $response->assertJsonPath('data.total_questions', 1);
        $response->assertJsonPath(
            'data.max_question_count',
            (int) config('fellowship.session.max_question_count', 200),
        );
    }

    public function test_a_manually_authored_question_is_eligible_for_a_session(): void
    {
        $this->actingAsStudent();

        // A Fellowship with no import behind it: its classification and its one
        // question were both written by hand.
        $fellowship = Fellowship::factory()->create(['name' => 'Hand-built Bank']);
        $filter = $fellowship->filters()->create(['name' => 'Performance Topic', 'is_performance' => true]);
        $fellowship->update(['performance_filter_id' => $filter->getKey()]);

        $question = $this->question($fellowship, topic: 'Cardiology');

        $this->countAvailable($fellowship, ['question_count' => 1])
            ->assertOk()
            ->assertJsonPath('data.available_questions', 1)
            ->assertJsonPath('data.can_generate', true);

        $session = $this->generateSession($fellowship, ['question_count' => 1]);

        $this->assertSame(
            $question->getKey(),
            $session->questions->first()->fellowship_question_id,
        );
        $this->assertSame(
            'Cardiology',
            $session->questions->first()->snapshot()->topicValue(),
        );

        // And Topic Performance classifies it under the hand-made topic, which
        // is the whole point of the classification being designated at all.
        $this->answerCurrent($session)->assertOk();

        $this->getJson($this->fellowshipsUrl."/{$fellowship->id}")
            ->assertOk()
            ->assertJsonPath('data.topics_completed', 1)
            ->assertJsonPath('data.performance_topics.0.value', 'Cardiology')
            ->assertJsonPath('data.topic_performance.0.topic_value', 'Cardiology')
            ->assertJsonPath('data.topic_performance.0.average_score', 100);
    }
}
