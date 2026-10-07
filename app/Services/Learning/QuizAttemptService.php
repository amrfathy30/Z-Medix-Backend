<?php

namespace App\Services\Learning;

use App\Enums\QuizAttemptStatus;
use App\Exceptions\Learning\StudyAccessException;
use App\Exceptions\Learning\StudyFlowException;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizAttemptQuestion;
use App\Models\User;
use App\Support\Learning\QuestionSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * The student's run through a chapter quiz.
 *
 * There is no pass mark, no required score and no time limit — the only timer a
 * student sees belongs to their study session. An attempt stays `in_progress`
 * when they walk away and is resumed rather than restarted; only once every
 * question is answered does it complete, and only then is a retry a new
 * attempt. Question order is shuffled once, at creation, and persisted, so
 * leaving and returning shows the same questions in the same order.
 *
 * Creating an attempt is the only moment the live questions and options are
 * read. Each one is frozen into the attempt as a snapshot, and from then on the
 * attempt is presented and graded entirely from those snapshots — so editing,
 * re-optioning or deleting a question afterwards cannot change what a past
 * attempt showed or what it scored.
 */
class QuizAttemptService
{
    public function __construct(
        private StudyAccessService $access,
        private ChapterProgressService $chapterProgress,
        private StudyPositionService $positions,
    ) {}

    /**
     * Resume the student's open attempt on this quiz, or open a new one.
     *
     * The student may enter before reading all of the chapter's pages: only
     * chapter access is checked.
     */
    public function startOrResume(User $student, Quiz $quiz): QuizAttempt
    {
        $this->access->assertCanAccessQuiz($student, $quiz);

        $attempt = $this->openAttemptFor($student, $quiz) ?? $this->createAttempt($student, $quiz);

        $this->positions->recordQuizAttempt($student, $attempt, $attempt->currentQuestion());

        return $attempt;
    }

    /**
     * Record the student's answer, final from this point on.
     *
     * The chosen option is validated against the attempt's own snapshot, and
     * its correctness is read from there — never from the request, and never
     * from the live `question_options` rows, which may have moved on since. The
     * attempt completes, taking the chapter with it, as soon as this was the
     * last unanswered question.
     */
    public function answer(User $student, QuizAttempt $attempt, QuizAttemptQuestion $attemptQuestion, string $optionKey): QuizAttemptQuestion
    {
        $this->assertOwnedBy($student, $attempt);
        $this->access->assertCanAccessQuiz($student, $attempt->quiz);

        if (! $attempt->isInProgress()) {
            throw StudyFlowException::attemptAlreadyCompleted();
        }

        if ($attemptQuestion->quiz_attempt_id !== $attempt->getKey()) {
            throw StudyFlowException::questionNotInAttempt();
        }

        if ($attemptQuestion->isAnswered()) {
            throw StudyFlowException::questionAlreadyAnswered();
        }

        $snapshot = $attemptQuestion->snapshot();

        if (! $snapshot->hasOption($optionKey)) {
            throw StudyFlowException::optionNotInQuestion();
        }

        DB::transaction(function () use ($attempt, $attemptQuestion, $snapshot, $optionKey): void {
            $attemptQuestion->update([
                'selected_option_key' => $optionKey,
                'is_correct' => $snapshot->isCorrect($optionKey),
                'answered_at' => now(),
            ]);

            if (! $attempt->hasUnansweredQuestions()) {
                $this->complete($attempt);
            }
        });

        $attempt->refresh();

        $this->positions->recordQuizAttempt($student, $attempt, $attempt->currentQuestion());

        return $attemptQuestion->refresh();
    }

    /**
     * The attempt the student may continue, or null when they have none open.
     */
    public function openAttemptFor(User $student, Quiz $quiz): ?QuizAttempt
    {
        /** @var QuizAttempt|null $attempt */
        $attempt = QuizAttempt::query()
            ->where('user_id', $student->getKey())
            ->where('quiz_id', $quiz->getKey())
            ->inProgress()
            ->orderByDesc('id')
            ->first();

        return $attempt;
    }

    public function assertOwnedBy(User $student, QuizAttempt $attempt): void
    {
        if ($attempt->user_id !== $student->getKey()) {
            throw StudyAccessException::notFound();
        }
    }

    /**
     * @return array{total_questions: int, answered_questions: int, correct_answers: int, incorrect_answers: int}
     */
    public function tally(QuizAttempt $attempt): array
    {
        $answered = $attempt->questions()->answered()->count();
        $correct = $attempt->questions()->answered()->where('is_correct', true)->count();

        return [
            'total_questions' => $attempt->questions()->count(),
            'answered_questions' => $answered,
            'correct_answers' => $correct,
            'incorrect_answers' => $answered - $correct,
        ];
    }

    /**
     * Freeze both the shuffled order and the question content for this attempt.
     *
     * This is the one place the live questions and options are read. Storing the
     * order is what lets the student leave mid-quiz and return to the same
     * sequence; storing the snapshots is what keeps that sequence readable and
     * gradable after the admin moves on. A retry therefore snapshots the quiz as
     * it stands then, not as an earlier attempt saw it.
     */
    private function createAttempt(User $student, Quiz $quiz): QuizAttempt
    {
        $questions = $quiz->questions()->with('options')->get()->all();

        if ($questions === []) {
            throw StudyFlowException::quizHasNoQuestions();
        }

        shuffle($questions);

        return DB::transaction(function () use ($student, $quiz, $questions): QuizAttempt {
            /** @var QuizAttempt $attempt */
            $attempt = QuizAttempt::query()->create([
                'user_id' => $student->getKey(),
                'quiz_id' => $quiz->getKey(),
                'status' => QuizAttemptStatus::InProgress,
                'started_at' => now(),
            ]);

            foreach ($questions as $index => $question) {
                $attempt->questions()->create([
                    'question_id' => $question->getKey(),
                    'position' => $index + 1,
                    'question_snapshot' => QuestionSnapshot::fromQuestion($question)->toArray(),
                ]);
            }

            return $attempt->load('questions');
        });
    }

    /**
     * Completing an attempt completes its chapter: there is no minimum score to
     * clear, so answering every question is the whole requirement, and the next
     * chapter becomes accessible through the usual access check.
     */
    private function complete(QuizAttempt $attempt): void
    {
        $attempt->update([
            'status' => QuizAttemptStatus::Completed,
            'completed_at' => now(),
        ]);

        $chapter = $attempt->quiz?->chapter;

        if ($chapter !== null) {
            $this->chapterProgress->markChapterCompleted($attempt->user, $chapter);
        }
    }
}
