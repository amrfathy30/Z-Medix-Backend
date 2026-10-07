<?php

namespace Tests\Feature\Study;

use App\Models\StudySession;
use App\Models\Subject;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

class StudySessionTest extends StudyTestCase
{
    public function test_a_student_starts_a_study_session_on_a_subject(): void
    {
        $subject = $this->subjectWithChapters();

        Sanctum::actingAs($this->student());

        $response = $this->postJson($this->studyUrl.'/sessions', ['subject_id' => $subject->id])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.subject_id', $subject->id)
            ->assertJsonPath('data.ended_at', null)
            ->assertJsonPath('data.duration_seconds', null)
            ->assertJsonPath('data.is_open', true);

        $this->assertNotNull($response->json('data.started_at'));
        $this->assertDatabaseCount('study_sessions', 1);
    }

    public function test_a_study_session_cannot_be_started_on_an_unpublished_subject(): void
    {
        $subject = Subject::factory()->draft()->create();

        Sanctum::actingAs($this->student());

        $this->postJson($this->studyUrl.'/sessions', ['subject_id' => $subject->id])
            ->assertNotFound()
            ->assertJsonPath('code', 'SUBJECT_UNAVAILABLE');

        $this->assertDatabaseCount('study_sessions', 0);
    }

    public function test_ending_a_study_session_stores_its_duration(): void
    {
        $subject = $this->subjectWithChapters();
        $student = $this->student();

        Sanctum::actingAs($student);

        Carbon::setTestNow('2026-10-07 10:00:00');
        $sessionId = $this->postJson($this->studyUrl.'/sessions', ['subject_id' => $subject->id])
            ->assertCreated()
            ->json('data.id');

        Carbon::setTestNow('2026-10-07 10:25:30');
        $this->postJson($this->studyUrl."/sessions/{$sessionId}/end")
            ->assertOk()
            ->assertJsonPath('data.duration_seconds', 1530)
            ->assertJsonPath('data.is_open', false);

        Carbon::setTestNow();

        $this->assertDatabaseHas('study_sessions', [
            'id' => $sessionId,
            'duration_seconds' => 1530,
        ]);
    }

    public function test_a_study_session_cannot_be_ended_twice(): void
    {
        $student = $this->student();
        $session = StudySession::factory()->ended()->create([
            'user_id' => $student->getKey(),
            'subject_id' => $this->subjectWithChapters()->getKey(),
        ]);

        Sanctum::actingAs($student);

        $this->postJson($this->studyUrl."/sessions/{$session->id}/end")
            ->assertStatus(422)
            ->assertJsonPath('code', 'STUDY_SESSION_ALREADY_ENDED');
    }

    public function test_a_student_cannot_end_another_students_study_session(): void
    {
        $session = StudySession::factory()->create([
            'user_id' => $this->student()->getKey(),
            'subject_id' => $this->subjectWithChapters()->getKey(),
        ]);

        Sanctum::actingAs($this->student());

        $this->postJson($this->studyUrl."/sessions/{$session->id}/end")
            ->assertNotFound()
            ->assertJsonPath('code', 'NOT_FOUND');

        $this->assertNull($session->refresh()->ended_at);
    }

    /**
     * The student owns the clock: nothing closes a session for them, so a
     * second one may be opened while the first is still running.
     */
    public function test_more_than_one_study_session_may_be_open_at_once(): void
    {
        $subject = $this->subjectWithChapters();

        Sanctum::actingAs($this->student());

        $this->postJson($this->studyUrl.'/sessions', ['subject_id' => $subject->id])->assertCreated();
        $this->postJson($this->studyUrl.'/sessions', ['subject_id' => $subject->id])->assertCreated();

        $this->assertSame(2, StudySession::query()->open()->count());
    }

    public function test_the_study_session_endpoints_reject_a_guest(): void
    {
        $subject = $this->subjectWithChapters();

        $this->postJson($this->studyUrl.'/sessions', ['subject_id' => $subject->id])->assertUnauthorized();
    }
}
