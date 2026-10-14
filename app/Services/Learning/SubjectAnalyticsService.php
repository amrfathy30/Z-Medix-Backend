<?php

namespace App\Services\Learning;

use App\Enums\QuizAttemptStatus;
use App\Models\Chapter;
use App\Models\ChapterPage;
use App\Models\QuizAttempt;
use App\Models\QuizAttemptQuestion;
use App\Models\StudentChapterPageProgress;
use App\Models\StudentChapterProgress;
use App\Models\StudySession;
use App\Models\Subject;
use App\Models\User;
use App\Support\Fellowship\FellowshipClock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Everything the student's Subject results screen reports, counted from what
 * they actually did.
 *
 * Nothing here is stored: every figure is derived at request time from the rows
 * the study flow already writes — `study_sessions` for time,
 * `student_chapter_page_progress` and `student_chapter_progress` for reading,
 * and the attempts' own answered question rows for scores. So no aggregate can
 * drift from the activity behind it, and adding a page or a chapter re-weighs
 * the subject on its own.
 *
 * Three rules run through all of it.
 *
 * What counts as an answer: a *confirmed* answer, counted the moment it is
 * given — including answers in an attempt the student has not finished. A
 * question answered again in a later attempt is a second answer, not a
 * correction of the first, so a retry counts on its own terms. The size of the
 * subject's question pool is never a denominator, which would turn a score into
 * a coverage measure. This is the same rule the Fellowship module applies to its
 * own activity, so `average_score` means the same thing on both screens
 * (business context §15).
 *
 * Where a score comes from: the attempt's answered rows, whose correctness was
 * graded against that attempt's frozen snapshot. Nothing here reads a live
 * question or option, so rewording or deleting content cannot move a figure the
 * student was already shown.
 *
 * What a subject still contains: live, published content. A chapter that has
 * been unpublished or deleted leaves the denominators, and the answers given
 * under it leave the totals with it — the same way
 * {@see ChapterProgressService::readingProgress()} already counts against the
 * chapter's live page count.
 */
class SubjectAnalyticsService
{
    /**
     * The whole Subject statistics payload in one place, so the endpoint and any
     * later screen cannot assemble a different set of figures.
     *
     * @return array<string, mixed>
     */
    public function statistics(User $student, Subject $subject, int $dailyDays = 7, ?Carbon $asOf = null): array
    {
        $asOf ??= now();
        $periods = $this->periods($student, $subject, $asOf);
        $attempts = $this->attemptRows($student, $subject);
        $studyTimeSeconds = $this->studyTimeSecondsFrom($periods);

        return [
            'chapters' => $this->chapterProgress($student, $subject),
            'reading_progress' => $this->readingProgress($student, $subject),
            'overall_result' => $this->overallResultFrom($attempts),
            'study_time_seconds' => $studyTimeSeconds,
            'study_time_hours' => $this->hours($studyTimeSeconds),
            'daily_study_time' => $this->dailyStudyTimeFrom($periods, $dailyDays, $asOf),
            'day_streak' => $this->dayStreak($student, $subject, $asOf),
            'quiz_performance' => $this->quizPerformanceFrom($student, $subject, $attempts),
        ];
    }

    /**
     * The subject's overall result: every confirmed answer the student has given
     * across every chapter quiz of this subject.
     *
     * `average_score` is null — not zero — when they have answered nothing;
     * zero would claim they got everything wrong.
     *
     * @return array{total_questions: int, answered_questions: int, correct_answers: int, incorrect_answers: int, average_score: float|null, total_attempts: int, completed_attempts: int, in_progress_attempts: int}
     */
    public function overallResult(User $student, Subject $subject): array
    {
        return $this->overallResultFrom($this->attemptRows($student, $subject));
    }

    /**
     * correct confirmed answers / all confirmed answers * 100, over the whole
     * subject — the Average Chapter Quiz Score.
     */
    public function averageScore(User $student, Subject $subject): ?float
    {
        return $this->overallResult($student, $subject)['average_score'];
    }

    /**
     * Chapters finished over the subject's published chapters.
     *
     * @return array{completed_chapters: int, total_chapters: int, percentage: float}
     */
    public function chapterProgress(User $student, Subject $subject): array
    {
        $chapterIds = $this->publishedChapterIds($subject);
        $total = count($chapterIds);

        $completed = $total === 0 ? 0 : StudentChapterProgress::query()
            ->where('user_id', $student->getKey())
            ->whereIn('chapter_id', $chapterIds)
            ->count();

        return [
            'completed_chapters' => $completed,
            'total_chapters' => $total,
            'percentage' => $this->percentage($completed, $total),
        ];
    }

    /**
     * Pages read over the pages of the subject's published chapters — the same
     * figure {@see ChapterProgressService::readingProgress()} reports for one
     * chapter, widened to the subject.
     *
     * @return array{completed_pages: int, total_pages: int, percentage: float}
     */
    public function readingProgress(User $student, Subject $subject): array
    {
        $chapterIds = $this->publishedChapterIds($subject);

        if ($chapterIds === []) {
            return ['completed_pages' => 0, 'total_pages' => 0, 'percentage' => 0.0];
        }

        $pageIds = ChapterPage::query()
            ->whereIn('chapter_id', $chapterIds)
            ->pluck('id');

        $completed = $pageIds->isEmpty() ? 0 : StudentChapterPageProgress::query()
            ->where('user_id', $student->getKey())
            ->whereIn('chapter_page_id', $pageIds)
            ->count();

        return [
            'completed_pages' => $completed,
            'total_pages' => $pageIds->count(),
            'percentage' => $this->percentage($completed, $pageIds->count()),
        ];
    }

    /**
     * Total study time on this subject, in seconds.
     *
     * A session still open counts up to this request, so the figure moves while
     * the student works. Sessions are never auto-closed, so one left open
     * yesterday is billed to the moment it is read, which is the same thing the
     * student's own running clock shows them.
     *
     * Always the sum of the same periods the Daily Study Hours chart is built
     * from, so the total and the chart cannot disagree.
     */
    public function studyTimeSeconds(User $student, Subject $subject, ?Carbon $asOf = null): int
    {
        $asOf ??= now();

        return $this->studyTimeSecondsFrom($this->periods($student, $subject, $asOf));
    }

    /**
     * Study time per calendar day, for the Daily Study Hours chart.
     *
     * A session that runs past midnight is split across the days it covers, in
     * the platform's configured timezone, so an evening session does not land
     * entirely on the day it began. A quiet day is a zero rather than a gap.
     *
     * @return list<array{date: string, seconds: int, hours: float}>
     */
    public function dailyStudyTime(User $student, Subject $subject, int $days = 7, ?Carbon $asOf = null): array
    {
        $asOf ??= now();

        return $this->dailyStudyTimeFrom($this->periods($student, $subject, $asOf), $days, $asOf);
    }

    /**
     * Consecutive days, up to today, on which the student studied this subject.
     *
     * A day counts when they confirmed an answer in one of its chapter quizzes
     * or marked one of its pages read: subject statistics cover "Subject/Chapter
     * learning and quiz activity" (business context §15), and reading a chapter
     * is the learning half of that. Days never come from how long a session was
     * left open — a session forgotten for a week is not a week's streak.
     *
     * Counting starts at today, or at yesterday when today has no activity yet,
     * so a streak is not reported as broken before the day is over.
     */
    public function dayStreak(User $student, Subject $subject, ?Carbon $asOf = null): int
    {
        $asOf ??= now();

        $days = $this->activeDays($student, $subject);

        if ($days === []) {
            return 0;
        }

        $today = FellowshipClock::local($asOf)->startOfDay();
        $cursor = isset($days[$today->toDateString()])
            ? $today
            : $today->copy()->subDay();

        if (! isset($days[$cursor->toDateString()])) {
            return 0;
        }

        $streak = 0;

        while (isset($days[$cursor->toDateString()])) {
            $streak++;
            $cursor = $cursor->subDay();
        }

        return $streak;
    }

    /**
     * Per-chapter quiz accuracy across the subject — the Quiz Performance panel.
     *
     * Every published chapter is reported, including those the student has not
     * attempted, so the panel's shape does not change as they progress.
     *
     * @return list<array{chapter_id: int, chapter_title: string, order: int, is_completed: bool, total_attempts: int, completed_attempts: int, answered_questions: int, correct_answers: int, incorrect_answers: int, average_score: float|null, best_score: float|null, latest_score: float|null}>
     */
    public function quizPerformance(User $student, Subject $subject): array
    {
        return $this->quizPerformanceFrom($student, $subject, $this->attemptRows($student, $subject));
    }

    /**
     * One row per attempt this student has made on this subject's chapter
     * quizzes, carrying that attempt's own question counts.
     *
     * A single query behind every score on the screen: the overall result and
     * the per-chapter panel are two groupings of the same rows, so they can
     * never disagree. The answered and correct counts are conditional sums over
     * one left join rather than a join per figure, and an attempt with no
     * answers yet still appears, with zeros.
     *
     * @return Collection<int, object>
     */
    private function attemptRows(User $student, Subject $subject): Collection
    {
        return QuizAttempt::query()
            ->join('quizzes', 'quizzes.id', '=', 'quiz_attempts.quiz_id')
            ->join('chapters', 'chapters.id', '=', 'quizzes.chapter_id')
            ->leftJoin(
                'quiz_attempt_questions',
                'quiz_attempt_questions.quiz_attempt_id',
                '=',
                'quiz_attempts.id',
            )
            ->where('quiz_attempts.user_id', $student->getKey())
            ->where('chapters.subject_id', $subject->getKey())
            // Soft-deleted chapters and quizzes are not the subject's content
            // any more, so neither are the answers given under them. The joins
            // are explicit, which means the models' own soft-delete scopes do
            // not apply here.
            ->whereNull('chapters.deleted_at')
            ->whereNull('quizzes.deleted_at')
            ->groupBy(
                'quiz_attempts.id',
                'quizzes.chapter_id',
                'quiz_attempts.status',
                'quiz_attempts.started_at',
            )
            ->orderBy('quizzes.chapter_id')
            ->orderBy('quiz_attempts.started_at')
            ->orderBy('quiz_attempts.id')
            ->selectRaw(implode(', ', [
                'quiz_attempts.id as attempt_id',
                'quizzes.chapter_id as chapter_id',
                'quiz_attempts.status as status',
                'quiz_attempts.started_at as started_at',
                'count(quiz_attempt_questions.id) as total_questions',
                'sum(case when quiz_attempt_questions.answered_at is not null then 1 else 0 end) as answered_questions',
                'sum(case when quiz_attempt_questions.answered_at is not null and quiz_attempt_questions.is_correct = 1 then 1 else 0 end) as correct_answers',
            ]))
            ->get();
    }

    /**
     * @param  Collection<int, object>  $attempts
     * @return array{total_questions: int, answered_questions: int, correct_answers: int, incorrect_answers: int, average_score: float|null, total_attempts: int, completed_attempts: int, in_progress_attempts: int}
     */
    private function overallResultFrom(Collection $attempts): array
    {
        $total = (int) $attempts->sum(fn (object $row): int => (int) $row->total_questions);
        $answered = (int) $attempts->sum(fn (object $row): int => (int) $row->answered_questions);
        $correct = (int) $attempts->sum(fn (object $row): int => (int) $row->correct_answers);
        $completed = $attempts->filter($this->isCompleted())->count();

        return [
            'total_questions' => $total,
            'answered_questions' => $answered,
            'correct_answers' => $correct,
            'incorrect_answers' => $answered - $correct,
            'average_score' => $answered === 0 ? null : $this->percentage($correct, $answered),
            'total_attempts' => $attempts->count(),
            'completed_attempts' => $completed,
            'in_progress_attempts' => $attempts->count() - $completed,
        ];
    }

    /**
     * @param  Collection<int, object>  $attempts
     * @return list<array{chapter_id: int, chapter_title: string, order: int, is_completed: bool, total_attempts: int, completed_attempts: int, answered_questions: int, correct_answers: int, incorrect_answers: int, average_score: float|null, best_score: float|null, latest_score: float|null}>
     */
    private function quizPerformanceFrom(User $student, Subject $subject, Collection $attempts): array
    {
        $chapters = $subject->publishedChapters()->get(['id', 'title', 'order']);

        if ($chapters->isEmpty()) {
            return [];
        }

        $completedChapterIds = array_flip(StudentChapterProgress::query()
            ->where('user_id', $student->getKey())
            ->whereIn('chapter_id', $chapters->pluck('id'))
            ->pluck('chapter_id')
            ->all());

        $byChapter = $attempts->groupBy(fn (object $row): int => (int) $row->chapter_id);

        return $chapters->map(function (Chapter $chapter) use ($byChapter, $completedChapterIds): array {
            /** @var Collection<int, object> $rows */
            $rows = $byChapter->get($chapter->getKey(), collect());

            $answered = (int) $rows->sum(fn (object $row): int => (int) $row->answered_questions);
            $correct = (int) $rows->sum(fn (object $row): int => (int) $row->correct_answers);

            // Scored attempts only: an attempt with nothing answered has no
            // score, and calling it zero would drag the best down to nothing.
            $scored = $rows->filter(fn (object $row): bool => (int) $row->answered_questions > 0);

            $scores = $scored->map(fn (object $row): float => $this->percentage(
                (int) $row->correct_answers,
                (int) $row->answered_questions,
            ));

            return [
                'chapter_id' => $chapter->getKey(),
                'chapter_title' => (string) $chapter->title,
                'order' => (int) $chapter->order,
                'is_completed' => isset($completedChapterIds[$chapter->getKey()]),
                'total_attempts' => $rows->count(),
                'completed_attempts' => $rows->filter($this->isCompleted())->count(),
                'answered_questions' => $answered,
                'correct_answers' => $correct,
                'incorrect_answers' => $answered - $correct,
                'average_score' => $answered === 0 ? null : $this->percentage($correct, $answered),
                'best_score' => $scores->isEmpty() ? null : (float) $scores->max(),
                // The attempts arrive oldest first, so the last scored one is
                // the student's most recent score.
                'latest_score' => $scores->isEmpty() ? null : (float) $scores->last(),
            ];
        })->values()->all();
    }

    /**
     * Calendar days with at least one confirmed answer or one page marked read
     * in this subject, as a lookup keyed by `Y-m-d` in the configured timezone.
     *
     * Bucketed in PHP rather than with a database date function: the timezone
     * conversion has to agree with the Daily Study Time chart beside it, and
     * date handling is the one thing MySQL and SQLite disagree about most
     * readily.
     *
     * @return array<string, true>
     */
    private function activeDays(User $student, Subject $subject): array
    {
        $chapterIds = $this->publishedChapterIds($subject);

        if ($chapterIds === []) {
            return [];
        }

        $answerDays = QuizAttemptQuestion::query()
            ->answered()
            ->whereHas('attempt', fn ($attempt) => $attempt
                ->where('user_id', $student->getKey())
                ->whereHas('quiz', fn ($quiz) => $quiz->whereIn('chapter_id', $chapterIds)))
            ->pluck('answered_at');

        $readingDays = StudentChapterPageProgress::query()
            ->where('user_id', $student->getKey())
            ->whereHas('chapterPage', fn ($page) => $page->whereIn('chapter_id', $chapterIds))
            ->pluck('completed_at');

        return $answerDays->merge($readingDays)
            ->filter()
            ->reduce(function (array $days, $instant): array {
                $days[FellowshipClock::dateOf($instant)] = true;

                return $days;
            }, []);
    }

    /**
     * Every study session of this student on this subject, resolved to a start
     * and an end — a session still open ending at the moment of the request.
     *
     * `seconds` is the span between the two, for every session. The total and
     * the daily chart are both built from these periods, so taking the length
     * from one place is what guarantees the total equals the sum of the days —
     * a session that runs past midnight is split between them and must not also
     * be counted by a different measure.
     *
     * This agrees with the stored `duration_seconds` by construction:
     * {@see StudySessionService::end()} writes exactly this span when the
     * student ends a session, and that column remains the per-session figure
     * the session's own payload reports.
     *
     * @return Collection<int, array{start: Carbon, end: Carbon, seconds: int}>
     */
    private function periods(User $student, Subject $subject, Carbon $asOf): Collection
    {
        return StudySession::query()
            ->where('user_id', $student->getKey())
            ->where('subject_id', $subject->getKey())
            ->reorder()
            ->orderBy('started_at')
            ->get()
            ->map(function (StudySession $session) use ($asOf): array {
                $start = $session->started_at->copy()->utc();
                $end = ($session->ended_at ?? $asOf)->copy()->utc();

                return [
                    'start' => $start,
                    'end' => $end,
                    'seconds' => max(0, (int) $start->diffInSeconds($end, absolute: true)),
                ];
            });
    }

    /**
     * @param  Collection<int, array{start: Carbon, end: Carbon, seconds: int}>  $periods
     */
    private function studyTimeSecondsFrom(Collection $periods): int
    {
        return (int) $periods->sum(fn (array $period): int => $period['seconds']);
    }

    /**
     * @param  Collection<int, array{start: Carbon, end: Carbon, seconds: int}>  $periods
     * @return list<array{date: string, seconds: int, hours: float}>
     */
    private function dailyStudyTimeFrom(Collection $periods, int $days, Carbon $asOf): array
    {
        $days = max(1, $days);
        $totals = [];

        foreach ($periods as $period) {
            foreach (FellowshipClock::splitAcrossDays($period['start'], $period['end']) as $date => $seconds) {
                $totals[$date] = ($totals[$date] ?? 0) + $seconds;
            }
        }

        $series = [];
        $cursor = FellowshipClock::local($asOf)->startOfDay()->subDays($days - 1);

        for ($index = 0; $index < $days; $index++) {
            $date = $cursor->toDateString();
            $seconds = (int) ($totals[$date] ?? 0);

            $series[] = ['date' => $date, 'seconds' => $seconds, 'hours' => $this->hours($seconds)];

            $cursor = $cursor->addDay();
        }

        return $series;
    }

    /**
     * @return array<int, int>
     */
    private function publishedChapterIds(Subject $subject): array
    {
        return $subject->publishedChapters()->pluck('id')->all();
    }

    /**
     * Status arrives off a joined query whose base model is {@see QuizAttempt},
     * so Eloquent may hand it back already cast to the enum — or as the raw
     * string, depending on the driver. Both are accepted rather than assuming
     * either.
     *
     * @return callable(object): bool
     */
    private function isCompleted(): callable
    {
        return static function (object $row): bool {
            $status = $row->status instanceof QuizAttemptStatus
                ? $row->status
                : QuizAttemptStatus::tryFrom((string) $row->status);

            return $status === QuizAttemptStatus::Completed;
        };
    }

    /**
     * Rounded to two decimal places: enough to distinguish 66.67 from 66.66
     * without pretending to more precision than a handful of answers supports.
     */
    private function percentage(int $part, int $whole): float
    {
        if ($whole === 0) {
            return 0.0;
        }

        return round($part / $whole * 100, 2);
    }

    private function hours(int $seconds): float
    {
        return round($seconds / 3600, 2);
    }
}
