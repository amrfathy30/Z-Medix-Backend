<?php

namespace Tests\Feature\Study;

use App\Exceptions\Learning\StudyFlowException;
use App\Models\StudySession;
use App\Models\Subject;
use App\Models\User;
use App\Services\Learning\StudySessionService;
use App\Services\Learning\SubjectAnalyticsService;
use App\Support\Fellowship\FellowshipClock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use RuntimeException;

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

    public function test_repeated_start_resumes_the_original_session_and_clock(): void
    {
        $subject = $this->subjectWithChapters();
        Sanctum::actingAs($this->student());
        $this->travelTo(Carbon::parse('2026-10-07 10:00:00'));
        $first = $this->postJson($this->studyUrl.'/sessions', ['subject_id' => $subject->id])->assertCreated()->json('data');
        $this->travel(20)->minutes();
        $this->postJson($this->studyUrl.'/sessions', ['subject_id' => $subject->id])
            ->assertOk()->assertJsonPath('data', $first);
        $this->assertDatabaseCount('study_sessions', 1);
    }

    public function test_switching_subjects_uses_one_transition_and_preserves_completed_history(): void
    {
        $student = $this->student();
        $firstSubject = $this->subjectWithChapters();
        $secondSubject = $this->subjectWithChapters();
        $history = StudySession::factory()->for($student)->ended()->create(['subject_id' => $firstSubject->id]);
        $original = $history->fresh()->getRawOriginal();
        Sanctum::actingAs($student);
        $this->travelTo(Carbon::parse('2026-10-07 10:00:00'));
        $id = $this->postJson($this->studyUrl.'/sessions', ['subject_id' => $firstSubject->id])->assertCreated()->json('data.id');
        $this->travel(25)->minutes();
        $next = $this->postJson($this->studyUrl.'/sessions', ['subject_id' => $secondSubject->id])->assertCreated()->json('data');
        $old = StudySession::findOrFail($id);
        $this->assertSame(1500, $old->duration_seconds);
        $this->assertSame($old->ended_at->toIso8601String(), $next['started_at']);
        $this->assertNull($next['duration_seconds']);
        $this->assertSame(1, $student->studySessions()->open()->count());
        $this->assertSame($original, $history->refresh()->getRawOriginal());
    }

    public function test_start_after_end_creates_a_new_id(): void
    {
        $subject = $this->subjectWithChapters();
        Sanctum::actingAs($this->student());
        $id = $this->postJson($this->studyUrl.'/sessions', ['subject_id' => $subject->id])->assertCreated()->json('data.id');
        $this->postJson($this->studyUrl."/sessions/{$id}/end")->assertOk();
        $next = $this->postJson($this->studyUrl.'/sessions', ['subject_id' => $subject->id])->assertCreated()->json('data.id');
        $this->assertNotSame($id, $next);
        $this->assertDatabaseCount('study_sessions', 2);
    }

    public function test_failed_switch_rolls_back_the_old_session_closure(): void
    {
        $student = $this->student();
        $first = Subject::factory()->create();
        $second = Subject::factory()->create();
        $service = app(StudySessionService::class);
        $old = $service->start($student, $first);
        $original = $old->fresh()->getRawOriginal();
        Event::listen('eloquent.creating: '.StudySession::class, function (): void {
            throw new RuntimeException('Simulated insert failure');
        });
        try {
            $service->start($student, $second);
            $this->fail('Expected the insert to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated insert failure', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.StudySession::class);
        }
        $this->assertSame($original, $old->refresh()->getRawOriginal());
        $this->assertDatabaseCount('study_sessions', 1);
    }

    public function test_stale_end_model_cannot_overwrite_recorded_time(): void
    {
        $student = $this->student();
        $session = app(StudySessionService::class)->start($student, Subject::factory()->create());
        $stale = $session->fresh();
        $this->travel(10)->minutes();
        $ended = app(StudySessionService::class)->end($student, $session)->getRawOriginal();
        $this->travel(10)->minutes();
        try {
            app(StudySessionService::class)->end($student, $stale);
            $this->fail('Expected already-ended error.');
        } catch (StudyFlowException) {
            $this->assertSame($ended, $session->refresh()->getRawOriginal());
        }
    }

    public function test_legacy_duplicates_keep_newest_timer_without_guessing_superseded_durations(): void
    {
        $student = $this->student();
        $subject = Subject::factory()->create();
        $other = Subject::factory()->create();
        $this->travelTo(Carbon::parse('2026-10-07 10:00:00'));
        $old = StudySession::factory()->for($student)->create(['subject_id' => $other->id]);
        $this->travel(10)->minutes();
        $tied = StudySession::factory()->for($student)->create(['subject_id' => $subject->id]);
        $newest = StudySession::factory()->for($student)->create(['subject_id' => $subject->id]);
        $unrelated = StudySession::factory()->create(['subject_id' => $subject->id]);
        $this->travel(10)->minutes();
        Sanctum::actingAs($student);
        $this->postJson($this->studyUrl.'/sessions', ['subject_id' => $subject->id])
            ->assertOk()->assertJsonPath('data.id', $newest->id);
        foreach ([$old, $tied] as $superseded) {
            $superseded->refresh();
            $this->assertTrue($superseded->ended_at->equalTo($superseded->started_at));
            $this->assertSame(0, $superseded->duration_seconds);
        }
        $this->assertSame(1, $student->studySessions()->open()->count());
        $this->assertNull($unrelated->refresh()->ended_at);
        $this->assertDatabaseCount('study_sessions', 4);
        $analytics = app(SubjectAnalyticsService::class);
        $this->assertSame(600, $analytics->studyTimeSeconds($student, $subject));
        $this->assertSame(0, $analytics->studyTimeSeconds($student, $other));
    }

    public function test_end_also_resolves_remaining_legacy_duplicates(): void
    {
        $student = $this->student();
        $subject = Subject::factory()->create();
        $sessions = StudySession::factory()->count(3)->for($student)->create(['subject_id' => $subject->id]);
        app(StudySessionService::class)->end($student, $sessions->first());
        $this->assertSame(1, $student->studySessions()->open()->count());
        $this->assertSame(0, $sessions->first()->refresh()->duration_seconds);
    }

    public function test_switch_across_midnight_preserves_analytics_totals_and_daily_split(): void
    {
        $student = $this->student();
        $first = Subject::factory()->create();
        $second = Subject::factory()->create();
        $midnight = FellowshipClock::startOfLocalDay(Carbon::parse('2026-10-08 12:00:00'));
        $this->travelTo($midnight->copy()->subMinutes(10));
        $service = app(StudySessionService::class);
        $service->start($student, $first);
        $this->travelTo($midnight->copy()->addMinutes(10));
        $service->start($student, $second);
        $this->travel(10)->minutes();
        $analytics = app(SubjectAnalyticsService::class);
        $this->assertSame(1200, $analytics->studyTimeSeconds($student, $first));
        $this->assertSame([600, 600], array_column($analytics->dailyStudyTime($student, $first, 2), 'seconds'));
        $this->assertSame(600, $analytics->studyTimeSeconds($student, $second));
    }

    public function test_unavailable_subject_cannot_close_an_existing_timer(): void
    {
        $student = $this->student();
        $session = app(StudySessionService::class)->start($student, Subject::factory()->create());
        Sanctum::actingAs($student);
        $this->postJson($this->studyUrl.'/sessions', ['subject_id' => Subject::factory()->draft()->create()->id])
            ->assertNotFound();
        $this->assertNull($session->refresh()->ended_at);
    }

    public function test_sessions_keep_running_without_browser_activity(): void
    {
        $student = $this->student();
        $subject = Subject::factory()->create();
        $this->travelTo(Carbon::parse('2026-10-07 10:00:00'));
        $session = app(StudySessionService::class)->start($student, $subject);
        $this->travel(2)->days();
        $resumed = app(StudySessionService::class)->start($student, $subject);
        $this->assertSame($session->id, $resumed->id);
        $this->assertNull($resumed->ended_at);
        $this->assertSame(172800, app(SubjectAnalyticsService::class)->studyTimeSeconds($student, $subject));
    }

    public function test_switching_sessions_preserves_reading_position_and_quiz_resume(): void
    {
        $subject = $this->subjectWithChapters();
        $other = Subject::factory()->create();
        $chapter = $subject->chapters()->first();
        $page = $chapter->pages()->first();
        Sanctum::actingAs($student = $this->student());
        $this->postJson($this->studyUrl."/chapter-pages/{$page->id}/complete")->assertOk();
        $position = $this->getJson($this->studyUrl."/subjects/{$subject->id}/resume")->assertOk()->json('data');
        $service = app(StudySessionService::class);
        $service->start($student, $subject);
        $service->start($student, $subject);
        $service->start($student, $other);
        $this->getJson($this->studyUrl."/subjects/{$subject->id}/resume")->assertOk()->assertJsonPath('data', $position);
        $this->assertDatabaseCount('student_chapter_page_progress', 1);
        $this->assertDatabaseCount('student_chapter_progress', 0);
        $quiz = $this->postJson($this->studyUrl."/chapters/{$chapter->id}/quiz/start")->assertCreated()->json('data');
        $service->start($student, $subject);
        $service->start($student, $other);
        $this->postJson($this->studyUrl."/chapters/{$chapter->id}/quiz/start")
            ->assertOk()->assertJsonPath('data.id', $quiz['id']);
        $this->assertDatabaseCount('quiz_attempts', 1);
    }

    public function test_subscription_denial_preserves_the_http_200_contract(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->postJson($this->studyUrl.'/sessions', ['subject_id' => Subject::factory()->create()->id])
            ->assertOk()->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
        $this->assertDatabaseCount('study_sessions', 0);
    }

    public function test_the_study_session_endpoints_reject_a_guest(): void
    {
        $subject = $this->subjectWithChapters();

        $this->postJson($this->studyUrl.'/sessions', ['subject_id' => $subject->id])->assertUnauthorized();
    }
}
