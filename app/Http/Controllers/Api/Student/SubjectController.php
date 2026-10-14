<?php

namespace App\Http\Controllers\Api\Student;

use App\Enums\ContentStatus;
use App\Exceptions\Learning\StudyException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Student\SubjectDetailsResource;
use App\Http\Resources\Api\Student\SubjectListItemResource;
use App\Models\Chapter;
use App\Models\StudentChapterPageProgress;
use App\Models\StudentChapterProgress;
use App\Models\Subject;
use App\Services\Learning\StudyAccessService;
use App\Services\Learning\SubjectAnalyticsService;
use App\Support\Api\ApiResponse;
use App\Support\Api\HandlesStudyExceptions;
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
    ) {}

    /**
     * Every subject the student may study, with how far through each they are.
     *
     * The chapter and book counts come from `withCount`, and the completion
     * figures from one aggregate across the whole page, so the cost does not
     * grow with the number of subjects listed.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 15), 1), 50);

        $subjects = Subject::query()
            ->published()
            ->withCount(['publishedChapters', 'publishedBooks'])
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage);

        $completed = $this->completedChaptersBySubject(
            $request->user()->getKey(),
            $subjects->getCollection()->pluck('id')->all(),
        );

        $subjects->getCollection()->transform(
            fn (Subject $subject): SubjectListItemResource => new SubjectListItemResource(
                $subject,
                (int) ($completed[$subject->getKey()] ?? 0),
            ),
        );

        return $this->paginatedResponse(SubjectListItemResource::collection($subjects));
    }

    /**
     * One subject: its chapter track with each chapter's state, and its books.
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
        $chapters = $subject->publishedChapters()->withCount('pages')->get();

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
            $this->analytics->chapterProgress($student, $subject),
            $this->analytics->readingProgress($student, $subject),
        ));
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
