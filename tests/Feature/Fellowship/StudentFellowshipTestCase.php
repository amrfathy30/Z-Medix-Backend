<?php

namespace Tests\Feature\Fellowship;

use App\Models\Fellowship;
use App\Models\FellowshipQuizSession;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * Shared setup for the student Fellowship API: the endpoint prefixes, and
 * helpers for generating a session and answering through it.
 */
abstract class StudentFellowshipTestCase extends FellowshipTestCase
{
    protected string $fellowshipsUrl = '/api/student/fellowships';

    protected string $sessionsUrl = '/api/student/fellowship-sessions';

    /**
     * Generate a session through the API.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function generate(Fellowship $fellowship, array $payload = []): TestResponse
    {
        return $this->postJson($this->fellowshipsUrl."/{$fellowship->id}/sessions", [
            'title' => 'Morning revision',
            'question_count' => 2,
            ...$payload,
        ]);
    }

    /**
     * Generate a session and return the model, so a test can assert against
     * state the response does not carry.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function generateSession(Fellowship $fellowship, array $payload = []): FellowshipQuizSession
    {
        $id = $this->generate($fellowship, $payload)->assertCreated()->json('data.id');

        return FellowshipQuizSession::query()->with('questions')->findOrFail($id);
    }

    protected function answer(FellowshipQuizSession $session, int $sessionQuestionId, string $optionKey): TestResponse
    {
        return $this->postJson(
            $this->sessionsUrl."/{$session->id}/questions/{$sessionQuestionId}/answer",
            ['option_key' => $optionKey],
        );
    }

    /**
     * Answer the question the session is on, choosing its snapshot's correct
     * option or a wrong one.
     */
    protected function answerCurrent(FellowshipQuizSession $session, bool $correctly = true): TestResponse
    {
        $question = $session->refresh()->currentQuestion();

        $this->assertNotNull($question, 'The session has no current question.');

        $snapshot = $question->snapshot();

        $key = $correctly
            ? (string) $snapshot->correctOptionKey()
            : (string) collect($snapshot->presentableOptions())
                ->first(fn (array $option): bool => $option['key'] !== $snapshot->correctOptionKey())['key'];

        return $this->answer($session, $question->getKey(), $key);
    }

    /**
     * Answer every remaining question, the given number of them correctly.
     */
    protected function answerAll(FellowshipQuizSession $session, int $correct = PHP_INT_MAX): void
    {
        $answered = 0;

        while ($session->refresh()->hasUnansweredQuestions()) {
            $this->answerCurrent($session, $answered < $correct)->assertOk();
            $answered++;
        }
    }

    protected function actingAsStudent(?User $student = null): User
    {
        $student ??= $this->student();

        $this->actingAs($student, 'sanctum');

        return $student;
    }
}
