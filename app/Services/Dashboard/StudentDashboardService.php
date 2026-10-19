<?php

namespace App\Services\Dashboard;

use App\Models\Chapter;
use App\Models\Fellowship;
use App\Models\FellowshipQuestion;
use App\Models\FellowshipQuizSession;
use App\Models\QuizAttempt;
use App\Models\ReportCase;
use App\Models\StudentBookmark;
use App\Models\StudentChapterProgress;
use App\Models\StudentStudyPosition;
use App\Models\StudySession;
use App\Models\Subject;
use App\Models\User;
use App\Services\Fellowship\FellowshipAnalyticsService;
use App\Services\Learning\StudyAccessService;
use App\Services\Learning\StudyResumeService;
use App\Services\Learning\SubjectAnalyticsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Everything the student's home screen reports, assembled from the records the
 * study and Fellowship modules already keep.
 *
 * Nothing here writes: no session is opened, no position is recorded and no
 * progress is touched, so the home screen can be polled without changing what
 * it is reporting. Every figure is read through the module that owns it —
 * {@see SubjectAnalyticsService} for study progress, {@see StudyResumeService}
 * for where a subject picks back up, {@see FellowshipAnalyticsService} for
 * session statistics — so the dashboard can never disagree with the screen the
 * student lands on next.
 *
 * Only published subjects, published chapters and active Fellowships take part,
 * which is the same visibility rule the dedicated endpoints enforce.
 */
class StudentDashboardService
{
    /**
     * Per-subject figures already answered during this request.
     *
     * Continue Learning and the Subjects section ask the same two questions
     * about the same subject — how far through it the student is, and which
     * chapter they are on — so the answers are kept rather than queried twice.
     * The service is resolved per request and reads one student, which is what
     * makes a key of the subject id alone sufficient.
     *
     * @var array<int, array{completed_chapters: int, total_chapters: int, percentage: float}>
     */
    private array $chapterProgress = [];

    /**
     * @var array<int, Chapter|null>
     */
    private array $currentChapters = [];

    public function __construct(
        private StudyAccessService $access,
        private StudyResumeService $resume,
        private SubjectAnalyticsService $subjectAnalytics,
        private FellowshipAnalyticsService $fellowshipAnalytics,
    ) {}

    /**
     * What the student has started and can pick back up, newest activity first.
     *
     * Two kinds of work count as started, each evidenced by a record the
     * student's own actions created:
     *
     * - a subject they hold a {@see StudentStudyPosition} in, which exists only
     *   once they have opened a page or entered a quiz;
     * - a Fellowship session still open — `in_progress` or `paused` — which
     *   they generated themselves and which resumes on its own question.
     *
     * A finished session is not something to continue, and a subject never
     * opened has no position, so neither appears here.
     *
     * @return list<array<string, mixed>>
     */
    public function continueLearning(User $student, int $limit): array
    {
        $items = [
            ...$this->continuableSubjects($student, $limit),
            ...$this->continuableFellowships($student, $limit),
        ];

        // Newest activity first. Two items recorded in the same second are
        // broken on the id of the row the activity was read from — the position
        // or the session — so the more recently written one wins, rather than
        // whichever subject or Fellowship happens to hold the higher id.
        usort(
            $items,
            static fn (array $first, array $second): int => [$second['last_active_at']?->getTimestamp() ?? 0, $second['sort_id']]
                <=> [$first['last_active_at']?->getTimestamp() ?? 0, $first['sort_id']],
        );

        return array_map(
            static function (array $item): array {
                unset($item['sort_id']);

                return $item;
            },
            array_slice($items, 0, $limit),
        );
    }

    /**
     * The Question Banks on offer, in the same order the Question Bank screen
     * lists them.
     *
     * `total_questions` is the bank's own available count, the figure
     * {@see Fellowship::availableQuestionsCount()} reports, taken here from one
     * `withCount` over the whole page. Topics are the Fellowship's Performance
     * Topic values, and difficulty levels are counted from the questions
     * actually in the bank rather than from the platform's full enum, so a bank
     * holding only easy questions reports one level.
     *
     * @return list<array{fellowship: Fellowship, stats: array{total_questions: int, total_topics: int, total_difficulty_levels: int}}>
     */
    public function fellowships(int $limit): array
    {
        /** @var Collection<int, Fellowship> $fellowships */
        $fellowships = Fellowship::query()
            ->active()
            ->with([
                'media',
                'performanceFilter' => fn ($query) => $query->withCount('values'),
            ])
            ->withCount('questions')
            ->orderBy('name')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $difficultyLevels = $this->difficultyLevelsByFellowship($fellowships);

        return $fellowships->map(fn (Fellowship $fellowship): array => [
            'fellowship' => $fellowship,
            'stats' => [
                'total_questions' => (int) $fellowship->questions_count,
                'total_topics' => (int) ($fellowship->performanceFilter?->values_count ?? 0),
                'total_difficulty_levels' => (int) ($difficultyLevels[$fellowship->getKey()] ?? 0),
            ],
        ])->values()->all();
    }

    /**
     * The study subjects on offer, with this student's standing in each.
     *
     * The completion figure is {@see SubjectAnalyticsService::chapterProgress()}
     * and the current chapter is
     * {@see StudyAccessService::currentAccessibleChapter()} — the same two
     * answers the Subjects screen is built from, so a card here and the same
     * card there carry the same numbers.
     *
     * @return list<array{subject: Subject, completed_chapters: int, current_chapter: Chapter|null}>
     */
    public function subjects(User $student, int $limit): array
    {
        /** @var Collection<int, Subject> $subjects */
        $subjects = Subject::query()
            ->published()
            ->with('media')
            ->withCount(['publishedChapters', 'publishedBooks'])
            ->orderBy('name')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        return $subjects->map(fn (Subject $subject): array => [
            'subject' => $subject,
            'completed_chapters' => $this->chapterProgressFor($student, $subject)['completed_chapters'],
            'current_chapter' => $this->currentChapterFor($student, $subject),
        ])->values()->all();
    }

    /**
     * Active report cases, newest first — the same query and the same order the
     * dedicated Report Cases endpoint serves.
     *
     * @return Collection<int, ReportCase>
     */
    public function reportCases(int $limit): Collection
    {
        return ReportCase::query()
            ->active()
            ->with('media')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * The student's latest activity, newest first, read from the rows their own
     * work left behind.
     *
     * Five sources are persisted and reported: a completed chapter quiz
     * attempt, a completed Fellowship session, a finished chapter, a closed
     * study session and a bookmark. Each is queried for its own newest few and
     * the merged list is cut to `$limit`, so one busy source cannot crowd the
     * others out of the query.
     *
     * Activity on content the student can no longer reach — an unpublished
     * subject or chapter, a deactivated Fellowship — is left out, because the
     * entry exists to navigate back to that content.
     *
     * @return list<array{type: string, title: string|null, description: string|null, occurred_at: Carbon|null, related_type: string, related_id: int}>
     */
    public function recentActivity(User $student, int $limit): array
    {
        $activities = [
            ...$this->completedQuizActivity($student, $limit),
            ...$this->completedFellowshipSessionActivity($student, $limit),
            ...$this->completedChapterActivity($student, $limit),
            ...$this->studySessionActivity($student, $limit),
            ...$this->bookmarkActivity($student, $limit),
        ];

        usort(
            $activities,
            static fn (array $first, array $second): int => [$second['occurred_at']?->getTimestamp() ?? 0, $second['type'], $second['related_id']]
                <=> [$first['occurred_at']?->getTimestamp() ?? 0, $first['type'], $first['related_id']],
        );

        return array_slice($activities, 0, $limit);
    }

    /**
     * Subjects the student holds a recorded position in, resolved through
     * {@see StudyResumeService} so the resume block the frontend receives is
     * the same one the dedicated resume endpoint returns.
     *
     * A position whose subject has since been unpublished is dropped by the
     * query; one whose chapter has become unreachable is reported by the resume
     * service as a fresh starting point, which is what the student would get
     * were they to open the subject.
     *
     * @return list<array<string, mixed>>
     */
    private function continuableSubjects(User $student, int $limit): array
    {
        /** @var Collection<int, StudentStudyPosition> $positions */
        $positions = StudentStudyPosition::query()
            ->where('user_id', $student->getKey())
            ->whereHas('subject', fn (Builder $query) => $query->published())
            ->with('subject.media')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $positions->map(function (StudentStudyPosition $position) use ($student): array {
            /** @var Subject $subject */
            $subject = $position->subject;
            $state = $this->resume->stateFor($student, $subject);

            return [
                'type' => 'subject',
                'sort_id' => $position->getKey(),
                'id' => $subject->getKey(),
                'title' => $subject->name,
                'image' => $subject->imageUrl(),
                'progress' => $this->chapterProgressFor($student, $subject),
                'current_chapter' => $state['chapter'],
                'resume' => $state,
                'session' => null,
                'last_active_at' => $position->updated_at,
            ];
        })->values()->all();
    }

    /**
     * Fellowship sessions the student left open, newest activity first.
     *
     * The statistics are {@see FellowshipAnalyticsService}'s own session
     * figures, so the card matches the session screen it reopens.
     *
     * @return list<array<string, mixed>>
     */
    private function continuableFellowships(User $student, int $limit): array
    {
        /** @var Collection<int, FellowshipQuizSession> $sessions */
        $sessions = FellowshipQuizSession::query()
            ->where('user_id', $student->getKey())
            ->open()
            ->whereHas('fellowship', fn (Builder $query) => $query->active())
            ->with('fellowship.media')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $sessions->map(function (FellowshipQuizSession $session): array {
            /** @var Fellowship $fellowship */
            $fellowship = $session->fellowship;
            $tally = $this->fellowshipAnalytics->sessionTally($session);

            return [
                'type' => 'fellowship',
                'sort_id' => $session->getKey(),
                'id' => $fellowship->getKey(),
                'title' => $fellowship->name,
                'image' => $fellowship->imageUrl(),
                'progress' => [
                    'answered_questions' => $tally['answered_questions'],
                    'total_questions' => $tally['total_questions'],
                    'percentage' => $this->percentage($tally['answered_questions'], $tally['total_questions']),
                ],
                'current_chapter' => null,
                'resume' => [
                    'session_id' => $session->getKey(),
                    'status' => $session->status?->value,
                    'current_position' => $session->current_position,
                    'can_resume' => true,
                ],
                'session' => [
                    'model' => $session,
                    'tally' => $tally,
                    'meta' => [
                        'study_time_seconds' => $this->fellowshipAnalytics->sessionStudyTimeSeconds($session),
                        'topics' => $this->fellowshipAnalytics->sessionTopics($session),
                    ],
                ],
                'last_active_at' => $session->updated_at,
            ];
        })->values()->all();
    }

    /**
     * How many distinct difficulties each Fellowship's bank actually holds,
     * keyed by Fellowship id — one grouped query for the whole page.
     * Soft-deleted questions are excluded by the model's own scope.
     *
     * @param  Collection<int, Fellowship>  $fellowships
     * @return array<int, int>
     */
    private function difficultyLevelsByFellowship(Collection $fellowships): array
    {
        if ($fellowships->isEmpty()) {
            return [];
        }

        return FellowshipQuestion::query()
            ->whereIn('fellowship_id', $fellowships->modelKeys())
            ->groupBy('fellowship_id')
            ->selectRaw('fellowship_id, count(distinct difficulty) as difficulty_levels')
            ->pluck('difficulty_levels', 'fellowship_id')
            ->map(static fn ($levels): int => (int) $levels)
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function completedQuizActivity(User $student, int $limit): array
    {
        return QuizAttempt::query()
            ->where('user_id', $student->getKey())
            ->completed()
            ->whereHas('quiz.chapter', fn (Builder $query) => $query->published())
            ->whereHas('quiz.chapter.subject', fn (Builder $query) => $query->published())
            ->with('quiz.chapter')
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(static fn (QuizAttempt $attempt): array => [
                'type' => 'quiz_completed',
                'title' => $attempt->quiz?->title,
                'description' => $attempt->quiz?->chapter?->title,
                'occurred_at' => $attempt->completed_at,
                'related_type' => 'quiz_attempt',
                'related_id' => (int) $attempt->getKey(),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function completedFellowshipSessionActivity(User $student, int $limit): array
    {
        return FellowshipQuizSession::query()
            ->where('user_id', $student->getKey())
            ->completed()
            ->whereHas('fellowship', fn (Builder $query) => $query->active())
            ->with('fellowship')
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(static fn (FellowshipQuizSession $session): array => [
                'type' => 'fellowship_session_completed',
                'title' => $session->title,
                'description' => $session->fellowship?->name,
                'occurred_at' => $session->completed_at,
                'related_type' => 'fellowship_session',
                'related_id' => (int) $session->getKey(),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function completedChapterActivity(User $student, int $limit): array
    {
        return StudentChapterProgress::query()
            ->where('user_id', $student->getKey())
            ->whereHas('chapter', fn (Builder $query) => $query->published())
            ->whereHas('chapter.subject', fn (Builder $query) => $query->published())
            ->with('chapter.subject')
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(static fn (StudentChapterProgress $progress): array => [
                'type' => 'chapter_completed',
                'title' => $progress->chapter?->title,
                'description' => $progress->chapter?->subject?->name,
                'occurred_at' => $progress->completed_at,
                'related_type' => 'chapter',
                'related_id' => (int) $progress->chapter_id,
            ])
            ->all();
    }

    /**
     * Study sessions the student closed themselves. An open session is not
     * reported: nothing closes one but the student, so it has no end to record.
     *
     * @return list<array<string, mixed>>
     */
    private function studySessionActivity(User $student, int $limit): array
    {
        return StudySession::query()
            ->where('user_id', $student->getKey())
            ->ended()
            ->whereHas('subject', fn (Builder $query) => $query->published())
            ->with('subject')
            ->orderByDesc('ended_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(static fn (StudySession $session): array => [
                'type' => 'study_session_ended',
                'title' => $session->subject?->name,
                'description' => null,
                'occurred_at' => $session->ended_at,
                'related_type' => 'subject',
                'related_id' => (int) $session->subject_id,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function bookmarkActivity(User $student, int $limit): array
    {
        return StudentBookmark::query()
            ->forStudent($student)
            ->whereHas('chapterPage.chapter', fn (Builder $query) => $query->published())
            ->whereHas('chapterPage.chapter.subject', fn (Builder $query) => $query->published())
            ->with('chapterPage.chapter')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(static fn (StudentBookmark $bookmark): array => [
                'type' => 'bookmark_added',
                'title' => $bookmark->chapterPage?->chapter?->title,
                'description' => $bookmark->selected_text,
                'occurred_at' => $bookmark->created_at,
                'related_type' => 'chapter_page',
                'related_id' => (int) $bookmark->chapter_page_id,
            ])
            ->all();
    }

    /**
     * Chapters finished over the subject's published chapters, answered once
     * per subject per request. {@see SubjectAnalyticsService::chapterProgress()}
     * is the owner of the calculation; this only remembers what it said.
     *
     * @return array{completed_chapters: int, total_chapters: int, percentage: float}
     */
    private function chapterProgressFor(User $student, Subject $subject): array
    {
        return $this->chapterProgress[$subject->getKey()] ??= $this->subjectAnalytics->chapterProgress($student, $subject);
    }

    /**
     * The chapter the student is on, answered once per subject per request. A
     * subject with no published chapters has none, so the absence of a key —
     * not a null value — is what says the question has not been asked yet.
     */
    private function currentChapterFor(User $student, Subject $subject): ?Chapter
    {
        if (array_key_exists($subject->getKey(), $this->currentChapters)) {
            return $this->currentChapters[$subject->getKey()];
        }

        return $this->currentChapters[$subject->getKey()] = $this->access->currentAccessibleChapter($student, $subject);
    }

    /**
     * Rounded the same way every other percentage in the study and Fellowship
     * modules is.
     */
    private function percentage(int $part, int $whole): float
    {
        if ($whole === 0) {
            return 0.0;
        }

        return round($part / $whole * 100, 2);
    }
}
