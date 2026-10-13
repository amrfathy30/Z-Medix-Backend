<?php

namespace Tests\Feature\Fellowship;

use App\Enums\FellowshipImportMappingTarget;
use App\Enums\QuizDifficulty;
use App\Exceptions\Fellowship\FellowshipImportException;
use App\Models\FellowshipQuestion;
use App\Services\Fellowship\FellowshipAnalyticsService;
use App\Services\Fellowship\FellowshipQuestionWriter;
use App\Support\Fellowship\ImportHeaderMap;
use App\Support\Fellowship\ImportRowPayload;

/**
 * Performance Topic integrity, across the live bank and the historical record.
 *
 * The question this file answers: once a student has answered under a topic,
 * can anything an admin does afterwards change what that answer was counted
 * under? It must not — and that holds because a session question carries its own
 * captured copy of the topic identity and name, and no analytics query follows a
 * live foreign key to find either.
 *
 * The worked example from the specification: a student answers Q1 under MRCP in
 * Session A; the admin then re-files Q1 under MRCS and removes MRCP. Session A
 * must still read MRCP and still be counted under MRCP, while a new Session B
 * uses MRCS, and no score moves.
 */
class FellowshipTopicIntegrityTest extends StudentFellowshipTestCase
{
    private function analytics(): FellowshipAnalyticsService
    {
        return app(FellowshipAnalyticsService::class);
    }

    // ------------------------------------------------- snapshot completeness

    public function test_session_generation_snapshots_the_whole_question(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $question = $this->question(
            $fellowship,
            topic: 'MRCP',
            difficulty: QuizDifficulty::Hard,
            text: 'Which antiplatelet?',
            options: 3,
        );
        $question->update(['explanation' => 'Aspirin is first line.']);

        $system = $this->dynamicFilter($fellowship, 'System', ['Cardiovascular']);
        $this->tagQuestion($question, $system, 'Cardiovascular');

        $session = $this->generateSession($fellowship, ['question_count' => 1]);
        $snapshot = $session->questions()->sole()->snapshot();

        $this->assertSame('Which antiplatelet?', $snapshot->questionText);
        $this->assertSame('Aspirin is first line.', $snapshot->explanation);
        $this->assertSame(QuizDifficulty::Hard->value, $snapshot->difficulty);
        $this->assertCount(3, $snapshot->options);
        $this->assertNotNull($snapshot->correctOptionKey());

        // Topic identity and display name, both captured.
        $this->assertSame($question->performance_filter_value_id, $snapshot->topicValueId());
        $this->assertSame('MRCP', $snapshot->topicValue());
        $this->assertSame(
            $fellowship->performance_filter_id,
            $snapshot->performanceTopic['filter_id'],
        );

        // And the dynamic filter values.
        $this->assertSame('System', $snapshot->filters[0]['filter_name']);
        $this->assertSame('Cardiovascular', $snapshot->filters[0]['value']);
    }

    public function test_the_queryable_topic_columns_mirror_the_snapshot(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();
        $question = $this->question($fellowship, topic: 'MRCP');

        $session = $this->generateSession($fellowship, ['question_count' => 1]);
        $sessionQuestion = $session->questions()->sole();

        $this->assertSame($sessionQuestion->snapshot()->topicValueId(), $sessionQuestion->performance_filter_value_id);
        $this->assertSame($sessionQuestion->snapshot()->topicValue(), $sessionQuestion->topic_value);
        $this->assertSame($question->performance_filter_value_id, $sessionQuestion->performance_filter_value_id);
    }

    public function test_the_captured_topic_id_is_not_a_live_foreign_key(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();
        $question = $this->question($fellowship, topic: 'MRCP');

        $session = $this->generateSession($fellowship, ['question_count' => 1]);
        $sessionQuestion = $session->questions()->sole();
        $topicId = $sessionQuestion->performance_filter_value_id;

        // Re-file the live question, then remove the topic value outright.
        $question->update(['performance_filter_value_id' => $this->topicValue($fellowship, 'MRCS')->getKey()]);
        $fellowship->performanceFilter->values()->whereKey($topicId)->delete();

        $this->assertSame(
            $topicId,
            $sessionQuestion->refresh()->performance_filter_value_id,
            'The captured id must not be nulled when the live row goes.',
        );
        $this->assertSame('MRCP', $sessionQuestion->topic_value);
    }

    // ------------------------------------------------------- the worked example

    public function test_refiling_a_questions_topic_affects_only_new_sessions(): void
    {
        $student = $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $question = $this->question($fellowship, topic: 'MRCP');

        // Session A, answered under MRCP.
        $sessionA = $this->generateSession($fellowship, ['title' => 'Session A', 'question_count' => 1]);
        $this->answerCurrent($sessionA)->assertOk();

        $scoreBefore = $this->analytics()->averageScore($student, $fellowship);

        // The admin re-files the question.
        $mrcs = $this->topicValue($fellowship, 'MRCS');
        $question->update(['performance_filter_value_id' => $mrcs->getKey()]);

        // Session B, generated afterwards, uses MRCS.
        $sessionB = $this->generateSession($fellowship, ['title' => 'Session B', 'question_count' => 1]);

        $this->assertSame('MRCP', $sessionA->questions()->sole()->topic_value);
        $this->assertSame('MRCS', $sessionB->questions()->sole()->topic_value);

        // Session A still reads MRCP over the API.
        $this->getJson($this->sessionsUrl."/{$sessionA->id}")
            ->assertOk()
            ->assertJsonPath('data.questions.0.performance_topic.value', 'MRCP');

        // And its answer is still counted under MRCP.
        $topics = collect($this->analytics()->topicPerformance($student, $fellowship))->keyBy('topic_value');

        $this->assertArrayHasKey('MRCP', $topics);
        $this->assertArrayNotHasKey('MRCS', $topics, 'Session B has no confirmed answer yet.');
        $this->assertSame(1, $topics['MRCP']['answered_questions']);
        $this->assertSame($scoreBefore, $this->analytics()->averageScore($student, $fellowship));
    }

    public function test_removing_the_original_topic_value_leaves_history_untouched(): void
    {
        $student = $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $question = $this->question($fellowship, topic: 'MRCP');

        $sessionA = $this->generateSession($fellowship, ['title' => 'Session A', 'question_count' => 1]);
        $this->answerCurrent($sessionA)->assertOk();

        $mrcpId = $fellowship->performanceFilter->values()->where('value', 'MRCP')->value('id');
        $scoreBefore = $this->analytics()->averageScore($student, $fellowship);

        // Re-file, then delete MRCP — the flow the integrity rule requires.
        $question->update(['performance_filter_value_id' => $this->topicValue($fellowship, 'MRCS')->getKey()]);
        $fellowship->performanceFilter->values()->whereKey($mrcpId)->delete();

        $this->assertDatabaseMissing('fellowship_filter_values', ['id' => $mrcpId]);

        // Session A still displays MRCP.
        $this->getJson($this->sessionsUrl."/{$sessionA->id}/result")
            ->assertOk()
            ->assertJsonPath('data.topic_performance.0.topic_value', 'MRCP')
            ->assertJsonPath('data.topic_performance.0.topic_value_id', $mrcpId);

        // And the fellowship analytics still count it under MRCP.
        $topics = $this->analytics()->topicPerformance($student, $fellowship);

        $this->assertCount(1, $topics);
        $this->assertSame('MRCP', $topics[0]['topic_value']);
        $this->assertSame($mrcpId, $topics[0]['topic_value_id']);
        $this->assertSame(1, $this->analytics()->topicsCompleted($student, $fellowship));
        $this->assertSame($scoreBefore, $this->analytics()->averageScore($student, $fellowship));
    }

    public function test_topics_completed_does_not_drop_when_a_topic_is_deleted(): void
    {
        $student = $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $first = $this->question($fellowship, topic: 'MRCP');
        $second = $this->question($fellowship, topic: 'MRCS');

        $session = $this->generateSession($fellowship, ['question_count' => 2]);
        $this->answerAll($session);

        $this->assertSame(2, $this->analytics()->topicsCompleted($student, $fellowship));

        $mrcpId = $fellowship->performanceFilter->values()->where('value', 'MRCP')->value('id');
        $first->update(['performance_filter_value_id' => $second->performance_filter_value_id]);
        $fellowship->performanceFilter->values()->whereKey($mrcpId)->delete();

        $this->assertSame(
            2,
            $this->analytics()->topicsCompleted($student, $fellowship),
            'A deleted live topic must not un-complete history.',
        );
    }

    // ------------------------------------------------------------ rename

    public function test_renaming_a_topic_leaves_previous_answers_under_the_old_name(): void
    {
        $student = $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $this->question($fellowship, topic: 'MRCP');

        $sessionA = $this->generateSession($fellowship, ['question_count' => 1]);
        $this->answerCurrent($sessionA)->assertOk();

        // The admin corrects the display name of the same topic value.
        $topic = $fellowship->performanceFilter->values()->where('value', 'MRCP')->sole();
        $topic->update(['value' => 'MRCP (Part 1)']);

        $this->assertSame('MRCP', $sessionA->questions()->sole()->refresh()->topic_value);

        $this->getJson($this->sessionsUrl."/{$sessionA->id}")
            ->assertOk()
            ->assertJsonPath('data.questions.0.performance_topic.value', 'MRCP');

        $topics = $this->analytics()->topicPerformance($student, $fellowship);

        $this->assertCount(1, $topics);
        $this->assertSame('MRCP', $topics[0]['topic_value'], 'History keeps the name it was answered under.');
    }

    public function test_a_rename_splits_old_and_new_answers_rather_than_rewriting_the_old(): void
    {
        $student = $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $this->question($fellowship, topic: 'MRCP');
        $this->question($fellowship, topic: 'MRCP');

        $sessionA = $this->generateSession($fellowship, ['question_count' => 1]);
        $this->answerCurrent($sessionA)->assertOk();

        $fellowship->performanceFilter->values()->where('value', 'MRCP')->sole()
            ->update(['value' => 'MRCP (Part 1)']);

        $sessionB = $this->generateSession($fellowship, ['question_count' => 1]);
        $this->answerCurrent($sessionB)->assertOk();

        $topics = collect($this->analytics()->topicPerformance($student, $fellowship))->keyBy('topic_value');

        $this->assertCount(2, $topics);
        $this->assertSame(1, $topics['MRCP']['answered_questions']);
        $this->assertSame(1, $topics['MRCP (Part 1)']['answered_questions']);
        // Same underlying identity, two names, two historical groups.
        $this->assertSame($topics['MRCP']['topic_value_id'], $topics['MRCP (Part 1)']['topic_value_id']);
    }

    // --------------------------------------------------- delete and recreate

    public function test_recreating_a_deleted_topic_with_the_same_name_does_not_merge_histories(): void
    {
        $student = $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $first = $this->question($fellowship, topic: 'MRCP');

        $sessionA = $this->generateSession($fellowship, ['question_count' => 1]);
        $this->answerCurrent($sessionA)->assertOk();

        $oldId = (int) $fellowship->performanceFilter->values()->where('value', 'MRCP')->value('id');

        // Re-file, delete MRCP, then recreate it under the same display name.
        $first->update(['performance_filter_value_id' => $this->topicValue($fellowship, 'Holding')->getKey()]);
        $fellowship->performanceFilter->values()->whereKey($oldId)->delete();

        $recreated = $this->topicValue($fellowship, 'MRCP');
        $this->assertNotSame($oldId, $recreated->getKey(), 'A recreated value is a new identity.');

        $this->question($fellowship, topic: 'MRCP');

        $sessionB = $this->generateSession($fellowship, [
            'question_count' => 1,
            'filters' => [$fellowship->performance_filter_id => [$recreated->getKey()]],
        ]);
        $this->answerCurrent($sessionB)->assertOk();

        $topics = $this->analytics()->topicPerformance($student, $fellowship);

        // Two separate identities, both displaying MRCP, reported apart.
        $this->assertCount(2, $topics);
        $this->assertEqualsCanonicalizing(['MRCP', 'MRCP'], array_column($topics, 'topic_value'));
        $this->assertEqualsCanonicalizing([$oldId, $recreated->getKey()], array_column($topics, 'topic_value_id'));
        $this->assertSame(2, $this->analytics()->topicsCompleted($student, $fellowship));
    }

    public function test_correctness_and_scores_never_move_through_any_of_it(): void
    {
        $student = $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $question = $this->question($fellowship, topic: 'MRCP');
        $this->question($fellowship, topic: 'MRCP');

        $session = $this->generateSession($fellowship, ['question_count' => 2]);
        $this->answerAll($session, correct: 1);

        $before = [
            'fellowship' => $this->analytics()->averageScore($student, $fellowship),
            'session' => $this->analytics()->sessionAverageScore($session->refresh()),
            'correctness' => $session->questions()->orderBy('position')->pluck('is_correct')->all(),
        ];

        // Rename, re-file, delete, recreate — everything at once.
        $topic = $fellowship->performanceFilter->values()->where('value', 'MRCP')->sole();
        $topic->update(['value' => 'Renamed']);

        $question->update(['performance_filter_value_id' => $this->topicValue($fellowship, 'Another')->getKey()]);
        $fellowship->questions()->get()->each(fn (FellowshipQuestion $q) => $q->delete());

        $after = [
            'fellowship' => $this->analytics()->averageScore($student, $fellowship),
            'session' => $this->analytics()->sessionAverageScore($session->refresh()),
            'correctness' => $session->questions()->orderBy('position')->pluck('is_correct')->all(),
        ];

        $this->assertSame($before, $after);
        $this->assertSame(50.0, $before['fellowship']);
    }

    public function test_a_session_question_stays_readable_after_its_question_and_topic_are_gone(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $question = $this->question($fellowship, topic: 'MRCP', text: 'Which antiplatelet?');

        $session = $this->generateSession($fellowship, ['question_count' => 1]);
        $this->answerCurrent($session)->assertOk();

        $topicId = (int) $question->performance_filter_value_id;

        $question->forceDelete();
        $fellowship->performanceFilter->values()->whereKey($topicId)->delete();

        $response = $this->getJson($this->sessionsUrl."/{$session->id}/result")->assertOk();

        $response->assertJsonPath('data.questions.0.question_text', 'Which antiplatelet?');
        $response->assertJsonPath('data.questions.0.performance_topic.value', 'MRCP');
        $response->assertJsonPath('data.questions.0.performance_topic.id', $topicId);
        $response->assertJsonPath('data.questions.0.is_correct', true);
        // The question reference is optional and drops; the snapshot does not.
        $response->assertJsonPath('data.questions.0.fellowship_question_id', null);
        $response->assertJsonPath('data.average_score', 100);
    }

    public function test_the_session_topic_summary_reads_captured_names(): void
    {
        $this->actingAsStudent();
        $fellowship = $this->fellowship();

        $this->question($fellowship, topic: 'MRCP');
        $this->question($fellowship, topic: 'MRCS');

        $session = $this->generateSession($fellowship, ['question_count' => 2]);
        $this->answerAll($session);

        $fellowship->performanceFilter->values()->get()->each(
            fn ($value) => $value->update(['value' => 'Renamed '.$value->getKey()])
        );

        $row = $this->getJson($this->fellowshipsUrl."/{$fellowship->id}/sessions")
            ->assertOk()
            ->json('data.0');

        $this->assertEqualsCanonicalizing(['MRCP', 'MRCS'], $row['performance_topics']);
    }

    // -------------------------------------------------------- required topic

    public function test_a_question_cannot_be_written_without_a_performance_topic(): void
    {
        $fellowship = $this->fellowship();

        $this->expectException(FellowshipImportException::class);

        app(FellowshipQuestionWriter::class)->create(
            $fellowship,
            ImportRowPayload::make(
                questionText: 'No topic at all?',
                explanation: null,
                difficulty: 'easy',
                correctAnswer: 'Aspirin',
                performanceTopic: null,
                options: ['Aspirin', 'Warfarin'],
                filters: [],
            ),
            $this->writerMap(),
        );
    }

    public function test_an_import_update_cannot_clear_a_questions_topic(): void
    {
        $fellowship = $this->fellowship();
        $question = $this->question($fellowship, topic: 'MRCP');
        $topicId = $question->performance_filter_value_id;

        // A payload with no topic keeps the one the question already had.
        app(FellowshipQuestionWriter::class)->update(
            $fellowship,
            $question,
            ImportRowPayload::make(
                questionText: 'Reworded?',
                explanation: null,
                difficulty: 'hard',
                correctAnswer: 'Aspirin',
                performanceTopic: null,
                options: ['Aspirin', 'Warfarin'],
                filters: [],
            ),
            $this->writerMap(),
        );

        $question->refresh();

        $this->assertSame('Reworded?', $question->question_text);
        $this->assertSame($topicId, $question->performance_filter_value_id, 'A required field is never cleared.');
    }

    public function test_a_question_cannot_be_written_with_an_unanswerable_option_set(): void
    {
        $fellowship = $this->fellowship();

        $this->expectException(FellowshipImportException::class);

        app(FellowshipQuestionWriter::class)->create(
            $fellowship,
            ImportRowPayload::make(
                questionText: 'One option only?',
                explanation: null,
                difficulty: 'easy',
                correctAnswer: 'Aspirin',
                performanceTopic: 'MRCP',
                options: ['Aspirin'],
                filters: [],
            ),
            $this->writerMap(),
        );
    }

    private function writerMap(): ImportHeaderMap
    {
        return ImportHeaderMap::fromArray([
            'Stem' => FellowshipImportMappingTarget::QuestionText->value,
            'A' => FellowshipImportMappingTarget::AnswerOption->value,
            'B' => FellowshipImportMappingTarget::AnswerOption->value,
            'Key' => FellowshipImportMappingTarget::CorrectAnswer->value,
            'Level' => FellowshipImportMappingTarget::Difficulty->value,
            'Area' => FellowshipImportMappingTarget::PerformanceTopic->value,
        ]);
    }
}
