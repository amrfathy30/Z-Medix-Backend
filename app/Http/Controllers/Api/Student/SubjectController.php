<?php

namespace App\Http\Controllers\Api\Student;

use App\Enums\ContentStatus;
use App\Exceptions\Learning\StudyException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Student\SubjectDetailsResource;
use App\Http\Resources\Api\Student\SubjectListItemResource;
use App\Models\Chapter;
use App\Models\QuizAttempt;
use App\Models\StudentChapterPageProgress;
use App\Models\StudentChapterProgress;
use App\Models\Subject;
use App\Services\Learning\QuizAttemptService;
use App\Services\Learning\StudyAccessService;
use App\Services\Learning\SubjectAnalyticsService;
use App\Support\Api\ApiResponse;
use App\Support\Api\HandlesStudyExceptions;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection as BaseCollection;

/**
 * Where the study flow starts: the subjects a student may study, and what one
 * subject holds.
 *
 * A subject holds two independent kinds of content — its chapter track and its
 * platform books — and this is the one place a client can discover either.
 * Everything deeper (a chapter, a page, a book, a quiz) is addressed by its own
 * endpoint from here.
 *
 * Publication is the only access rule there is: an unpublished subject is not
 * listed and cannot be opened, and nothing unpublished inside one is reported.
 */
class SubjectController extends Controller
{
    use ApiResponse, HandlesStudyExceptions;

    public function __construct(
        private StudyAccessService $access,
        private SubjectAnalyticsService $analytics,
        private QuizAttemptService $attempts,
    ) {}

    /**
     * Every subject the student may study, with how far through each they are
     * and the chapter they are on.
     *
     * `search` narrows the list to subjects whose name contains it.
     *
     * The chapter and book counts come from `withCount`, and the completion
     * and current-chapter figures from one aggregate each across the whole
     * page, so the cost does not grow with the number of subjects listed.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $perPage = min(max((int) $request->integer('per_page', 15), 1), 50);

        $subjects = Subject::query()
            ->published()
            ->when(
                $request->filled('search'),
                fn ($query) => $query->where('name', 'LIKE', '%'.$request->string('search')->toString().'%'),
            )
            ->withCount(['publishedChapters', 'publishedBooks'])
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();

        $subjectIds = $subjects->getCollection()->pluck('id')->all();
        $studentId = $request->user()->getKey();

        $completed = $this->completedChaptersBySubject($studentId, $subjectIds);
        $current = $this->currentChapterBySubject($studentId, $subjectIds);

        $subjects->getCollection()->transform(
            fn (Subject $subject): SubjectListItemResource => new SubjectListItemResource(
                $subject,
                (int) ($completed[$subject->getKey()] ?? 0),
                $current[$subject->getKey()] ?? null,
            ),
        );

        return $this->paginatedResponse(SubjectListItemResource::collection($subjects));
    }

    /**
     * One subject: its chapter track with each chapter's state, its chapter
     * quizzes, and its books.
     *
     * `is_accessible` is the backend's own answer to whether the student may
     * open a chapter yet, decided by the same rule the chapter endpoint
     * enforces — so the client can draw a locked chapter without guessing, and
     * cannot unlock one by drawing it unlocked.
     */
    public function show(Request $request, Subject $subject): JsonResponse
    {
        $student = $request->user();

        try {
            $this->access->assertCanAccessSubject($student, $subject);
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        /** @var Collection<int, Chapter> $chapters */
        $chapters = $subject->publishedChapters()
            ->withCount('pages')
            ->with(['quiz' => fn ($query) => $query->withCount('questions')])
            ->get();

        $subject->setRelation(
            'publishedBooks',
            $subject->publishedBooks()->withCount('pages')->get(),
        );

        $accessibleIds = array_flip($this->access->accessibleChapterIds($student, $subject));
        $completedIds = array_flip($this->completedChapterIds($student->getKey(), $chapters));
        $pagesRead = $this->pagesReadByChapter($student->getKey(), $chapters);

        $rows = $chapters->map(function (Chapter $chapter) use ($accessibleIds, $completedIds, $pagesRead): array {
            $totalPages = (int) ($chapter->pages_count ?? 0);
            $readPages = (int) ($pagesRead[$chapter->getKey()] ?? 0);

            return [
                'id' => $chapter->getKey(),
                'title' => $chapter->title,
                'references' => $chapter->references,
                'order' => (int) $chapter->order,
                'is_completed' => isset($completedIds[$chapter->getKey()]),
                'is_accessible' => isset($accessibleIds[$chapter->getKey()]),
                'reading_progress' => [
                    'completed_pages' => $readPages,
                    'total_pages' => $totalPages,
                    'percentage' => $totalPages === 0 ? 0.0 : round($readPages / $totalPages * 100, 2),
                ],
            ];
        })->values()->all();

        return $this->successResponse(new SubjectDetailsResource(
            $subject,
            $rows,
            $this->quizRows($student->getKey(), $chapters, $accessibleIds),
            $this->analytics->chapterProgress($student, $subject),
            $this->analytics->readingProgress($student, $subject),
        ));
    }

    /**
     * The subject's chapter quizzes in track order, each with how far this
     * student has got with it.
     *
     * A quiz is reachable exactly when its chapter is, so `is_accessible` is
     * the chapter's own answer. The latest attempt is scored through
     * {@see QuizAttemptService::tallyFromCounts()}, the same formula the
     * attempt history uses, so a row here matches the attempt it points at.
     * Attempt figures come from two queries for the whole subject.
     *
     * @param  Collection<int, Chapter>  $chapters
     * @param  array<int, int>  $accessibleIds
     * @return list<array<string, mixed>>
     */
    private function quizRows(int $studentId, Collection $chapters, array $accessibleIds): array
    {
        $withQuiz = $chapters->filter(fn (Chapter $chapter): bool => $chapter->quiz !== null);

        if ($withQuiz->isEmpty()) {
            return [];
        }

        $summaries = QuizAttempt::query()
            ->where('user_id', $studentId)
            ->whereIn('quiz_id', $withQuiz->map(fn (Chapter $chapter): int => $chapter->quiz->getKey())->all())
            ->groupBy('quiz_id')
            ->selectRaw('quiz_id, count(*) as attempts, max(id) as latest_id')
            ->get()
            ->keyBy('quiz_id');

        $latest = $summaries->isEmpty() ? collect() : QuizAttempt::query()
            ->whereIn('id', $summaries->pluck('latest_id'))
            ->withCount([
                'questions as questions_total',
                'questions as questions_answered' => fn (Builder $query) => $query->whereNotNull('answered_at'),
                'questions as questions_correct' => fn (Builder $query) => $query
                    ->whereNotNull('answered_at')
                    ->where('is_correct', true),
            ])
            ->get()
            ->keyBy('quiz_id');

        return $withQuiz->map(function (Chapter $chapter) use ($summaries, $latest, $accessibleIds): array {
            $quiz = $chapter->quiz;
            /** @var QuizAttempt|null $attempt */
            $attempt = $latest->get($quiz->getKey());

            return [
                'id' => $quiz->getKey(),
                'chapter_id' => $chapter->getKey(),
                'chapter_title' => $chapter->title,
                'title' => $quiz->title,
                'difficulty' => $quiz->difficulty?->value,
                'question_count' => (int) $quiz->questions_count,
                'is_accessible' => isset($accessibleIds[$chapter->getKey()]),
                'attempts_count' => (int) ($summaries->get($quiz->getKey())?->attempts ?? 0),
                'last_attempt' => $attempt === null ? null : [
                    'id' => $attempt->getKey(),
                    'status' => $attempt->status?->value,
                    'percentage' => $this->attempts->tallyFromCounts(
                        (int) $attempt->questions_total,
                        (int) $attempt->questions_answered,
                        (int) $attempt->questions_correct,
                    )['percentage'],
                ],
            ];
        })->values()->all();
    }

    /**
     * Completed chapter counts for a page of subjects, keyed by subject id.
     *
     * @param  array<int, int>  $subjectIds
     * @return BaseCollection<int, int>
     */
    private function completedChaptersBySubject(int $studentId, array $subjectIds): BaseCollection
    {
        if ($subjectIds === []) {
            return collect();
        }

        return StudentChapterProgress::query()
            ->join('chapters', 'chapters.id', '=', 'student_chapter_progress.chapter_id')
            ->where('student_chapter_progress.user_id', $studentId)
            ->whereIn('chapters.subject_id', $subjectIds)
            ->where('chapters.status', ContentStatus::Published->value)
            ->whereNull('chapters.deleted_at')
            ->groupBy('chapters.subject_id')
            ->selectRaw('chapters.subject_id as subject_id, count(*) as completed')
            ->pluck('completed', 'subject_id');
    }

    /**
     * The chapter the student is on in each of a page of subjects, keyed by
     * subject id: the first published chapter they have not completed, or the
     * last one once the whole subject is done. Subjects with no published
     * chapters are absent.
     *
     * The same rule as {@see StudyAccessService::currentAccessibleChapter()},
     * answered for the whole page in two queries instead of two per subject.
     *
     * @param  array<int, int>  $subjectIds
     * @return array<int, Chapter>
     */
    private function currentChapterBySubject(int $studentId, array $subjectIds): array
    {
        if ($subjectIds === []) {
            return [];
        }

        $chapters = Chapter::query()
            ->published()
            ->whereIn('subject_id', $subjectIds)
            ->orderBy('order')
            ->orderBy('id')
            ->get(['id', 'subject_id', 'title', 'order']);

        $completed = array_flip(
            StudentChapterProgress::query()
                ->where('user_id', $studentId)
                ->whereIn('chapter_id', $chapters->pluck('id'))
                ->pluck('chapter_id')
                ->all(),
        );

        $current = [];

        foreach ($chapters->groupBy('subject_id') as $subjectId => $track) {
            $current[$subjectId] = $track->first(
                fn (Chapter $chapter): bool => ! isset($completed[$chapter->getKey()]),
            ) ?? $track->last();
        }

        return $current;
    }

    /**
     * @param  Collection<int, Chapter>  $chapters
     * @return array<int, int>
     */
    private function completedChapterIds(int $studentId, Collection $chapters): array
    {
        if ($chapters->isEmpty()) {
            return [];
        }

        return StudentChapterProgress::query()
            ->where('user_id', $studentId)
            ->whereIn('chapter_id', $chapters->pluck('id'))
            ->pluck('chapter_id')
            ->all();
    }

    /**
     * Pages read per chapter, keyed by chapter id — one query for the whole
     * track rather than one per chapter.
     *
     * @param  Collection<int, Chapter>  $chapters
     * @return BaseCollection<int, int>
     */
    private function pagesReadByChapter(int $studentId, Collection $chapters): BaseCollection
    {
        if ($chapters->isEmpty()) {
            return collect();
        }

        return StudentChapterPageProgress::query()
            ->join(
                'chapter_pages',
                'chapter_pages.id',
                '=',
                'student_chapter_page_progress.chapter_page_id',
            )
            ->where('student_chapter_page_progress.user_id', $studentId)
            ->whereIn('chapter_pages.chapter_id', $chapters->pluck('id'))
            ->whereNull('chapter_pages.deleted_at')
            ->groupBy('chapter_pages.chapter_id')
            ->selectRaw('chapter_pages.chapter_id as chapter_id, count(*) as read_pages')
            ->pluck('read_pages', 'chapter_id');
    }
}
