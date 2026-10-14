<?php

namespace App\Console\Commands\Smoke;

use App\Enums\AccountStatus;
use App\Enums\OtpPurpose;
use App\Enums\OtpStatus;
use App\Enums\QuizAttemptStatus;
use App\Models\Chapter;
use App\Models\QuizAttempt;
use App\Models\StudentChapterProgress;
use App\Models\Subject;
use App\Models\User;
use App\Services\Learning\StudyAccessService;
use App\Support\Smoke\SmokeApiClient;
use App\Support\Smoke\SmokeApiResponse;
use App\Support\Smoke\SmokeFailure;
use App\Support\Smoke\SmokeOtpInbox;
use App\Support\Smoke\SmokeStudyFixtures;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The student study flow, end to end, against the local database.
 *
 * This is not a PHPUnit test and deliberately does not behave like one: nothing
 * is rolled back, so every record the run produces stays in the local database
 * to be inspected afterwards. What it checks is business behaviour, not status
 * codes — that a locked chapter is locked, that a score is the score the answers
 * earn, that completing a quiz is what unlocks the next chapter.
 *
 * Everything a student does goes through the real API. Content a student could
 * never create — the subject, its chapters, pages and questions — is built
 * through the admin's own service path by {@see SmokeStudyFixtures}. Progress is
 * never written directly: if the flow does not produce it, the run fails.
 *
 * Re-running is safe. Only records carrying the smoke marker, plus the one fixed
 * smoke account, are removed first; no table is truncated, no migration or
 * seeder is invoked, and the command refuses to run outside local and testing.
 */
class StudentStudyFlowSmokeCommand extends Command
{
    protected $signature = 'smoke:student-study';

    protected $description = 'Run the student study flow end to end against the local database, leaving the smoke data in place for inspection';

    /** Environments this scenario may touch. Never production. */
    private const ALLOWED_ENVIRONMENTS = ['local', 'testing'];

    /**
     * Tables the scenario writes through. Checked before anything is created so
     * a database that is behind on migrations is reported as such, rather than
     * surfacing as a 500 halfway through the flow. The command never migrates.
     *
     * @var list<string>
     */
    private const REQUIRED_TABLES = [
        'users',
        'phone_numbers',
        'email_verification_otps',
        'personal_access_tokens',
        'subjects',
        'chapters',
        'chapter_pages',
        'quizzes',
        'questions',
        'question_options',
        'quiz_attempts',
        'quiz_attempt_questions',
        'student_chapter_progress',
        'student_chapter_page_progress',
        'student_study_positions',
        'study_sessions',
        'student_highlights',
        'student_notes',
    ];

    private int $passes = 0;

    /**
     * Interesting ids collected along the way, printed at the end.
     *
     * @var array<string, int|string|null>
     */
    private array $report = [];

    public function __construct(
        private readonly SmokeApiClient $api,
        private readonly SmokeStudyFixtures $fixtures,
        private readonly SmokeOtpInbox $otpInbox,
        private readonly StudyAccessService $access,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->environmentIsSafe()) {
            $this->components->error(sprintf(
                'Refusing to run in the "%s" environment. This command is for %s only.',
                app()->environment(),
                implode(' and ', self::ALLOWED_ENVIRONMENTS),
            ));

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('<options=bold>SMOKE TEST — Z-MEDICS</>');
        $this->line(sprintf(
            'Database connection: %s   Database: %s',
            DB::connection()->getName(),
            (string) DB::connection()->getDatabaseName(),
        ));
        $this->newLine();

        try {
            $this->runScenario();
        } catch (SmokeFailure $failure) {
            $this->reportFailure($failure);

            return self::FAILURE;
        }

        $this->newLine();
        $this->line("<fg=green;options=bold>SMOKE TEST PASSED</> ({$this->passes} checks)");
        $this->printReport();

        return self::SUCCESS;
    }

    /**
     * The scenario itself, in the order a student would live it.
     */
    private function runScenario(): void
    {
        $this->assertSchemaIsReady();

        $subject = $this->prepareContent();
        [$chapterOne, $chapterTwo] = $this->orderedChapters($subject);

        $student = $this->registerStudent();
        $this->rejectLoginBeforeVerification();
        $this->verifyStudent($student);
        $token = $this->login($student);
        $this->api->authenticateWith($token);
        $this->retrieveProfile($student);

        $sessionId = $this->startStudySession($subject);
        $this->confirmSubjectEntryPoint($subject, $chapterOne);
        $this->confirmChapterLocked($chapterTwo);

        $this->studyChapter($chapterOne, annotate: true);
        $attemptOne = $this->takeQuiz($chapterOne, wrongAnswers: 1);
        $this->confirmChapterCompleted($chapterOne);
        $this->confirmSubjectProgress($student, $subject, expectedCompleted: 1);
        $this->confirmResumeMovedOn($subject, $chapterTwo);

        $this->confirmChapterUnlocked($chapterTwo);
        $this->studyChapter($chapterTwo, annotate: false);
        $attemptTwo = $this->takeQuiz($chapterTwo, wrongAnswers: 0);
        $this->confirmChapterCompleted($chapterTwo);
        $this->confirmSubjectProgress($student, $subject, expectedCompleted: 2);

        $this->endStudySession($sessionId);
        $this->confirmQuizHistory($student, [$attemptOne, $attemptTwo]);
        $this->confirmStudyActivity($student, $subject);

        $this->report['Student ID'] = $student->getKey();
        $this->report['Student Email'] = $student->email;
        $this->report['Subject ID'] = $subject->getKey();
        $this->report['Chapter 1 ID'] = $chapterOne->getKey();
        $this->report['Chapter 2 ID'] = $chapterTwo->getKey();
        $this->report['Quiz IDs'] = $chapterOne->quiz->getKey().', '.$chapterTwo->quiz->getKey();
        $this->report['Attempt IDs'] = $attemptOne.', '.$attemptTwo;
        $this->report['Study session ID'] = $sessionId;
        $this->report['Database connection'] = DB::connection()->getName();
        $this->report['Database name'] = (string) DB::connection()->getDatabaseName();
    }

    // ── Content ───────────────────────────────────────────────────────────────

    private function prepareContent(): Subject
    {
        $removed = $this->fixtures->cleanPreviousRun();

        $this->pass(sprintf(
            'Previous smoke data cleaned (subjects: %d, students: %d, phones: %d, tokens: %d)',
            $removed['subjects'],
            $removed['students'],
            $removed['phone_numbers'],
            $removed['api_tokens'],
        ));

        $subject = $this->fixtures->build();

        $this->assert(
            'Smoke content prepared',
            $subject->isPublished(),
            'The smoke subject was not created as published content.',
        );

        $chapters = $subject->publishedChapters()->get();

        $this->assert(
            'Smoke content prepared',
            $chapters->count() === 2,
            'Expected 2 published smoke chapters, found '.$chapters->count().'.',
        );

        foreach ($chapters as $chapter) {
            $this->assert(
                'Smoke content prepared',
                $chapter->pages()->count() === 3 && $chapter->quizQuestionCount() === 3,
                sprintf(
                    'Chapter "%s" should hold 3 pages and 3 quiz questions, found %d and %d.',
                    $chapter->title,
                    $chapter->pages()->count(),
                    $chapter->quizQuestionCount(),
                ),
            );
        }

        $this->pass('Smoke content prepared');

        return $subject;
    }

    /**
     * The subject's chapters in the sequence the application itself orders them.
     *
     * @return array{0: Chapter, 1: Chapter}
     */
    private function orderedChapters(Subject $subject): array
    {
        $chapters = $subject->publishedChapters()->get();
        $first = $chapters->first();
        $second = $chapters->get(1);

        $this->assert(
            'Chapters ordered correctly',
            $first->title === SmokeStudyFixtures::CHAPTER_ONE_TITLE
                && $second->title === SmokeStudyFixtures::CHAPTER_TWO_TITLE,
            sprintf('Chapter sequence was "%s" then "%s".', $first->title, $second->title),
        );

        $this->assert(
            'Chapters ordered correctly',
            $first->order < $second->order,
            sprintf('Chapter order values were %d and %d.', $first->order, $second->order),
        );

        $this->assert(
            'Chapters ordered correctly',
            $this->access->previousPublishedChapter($second)?->is($first) === true
                && $this->access->previousPublishedChapter($first) === null,
            'The application does not see chapter 1 as the predecessor of chapter 2.',
        );

        $this->pass('Chapters ordered correctly');

        return [$first->load('quiz'), $second->load('quiz')];
    }

    // ── Account ───────────────────────────────────────────────────────────────

    private function registerStudent(): User
    {
        $this->otpInbox->intercept();

        $countryId = $this->egyptCountryId();

        $response = $this->api->post('/api/public/auth/register', [
            'full_name' => SmokeStudyFixtures::STUDENT_NAME,
            'email' => SmokeStudyFixtures::STUDENT_EMAIL,
            'phone' => SmokeStudyFixtures::STUDENT_PHONE,
            'country_id' => $countryId,
            'password' => SmokeStudyFixtures::STUDENT_PASSWORD,
            'password_confirmation' => SmokeStudyFixtures::STUDENT_PASSWORD,
        ]);

        $this->assertStatus('Student registered', $response, 201);
        $this->assertSame('Student registered', SmokeStudyFixtures::STUDENT_EMAIL, $response->json('data.email'), 'registered email', $response);

        $student = User::query()->where('email', SmokeStudyFixtures::STUDENT_EMAIL)->first();

        $this->assert(
            'Student registered',
            $student !== null,
            'No user row exists for the registered email.',
            $response,
        );

        $this->assert(
            'Student registered',
            $student->status === AccountStatus::Pending && ! $student->hasVerifiedEmail(),
            sprintf(
                'A new account must start pending and unverified; found status "%s", verified at %s.',
                $student->status?->value,
                $student->email_verified_at?->toIso8601String() ?? 'null',
            ),
            $response,
        );

        $this->assert(
            'Student registered',
            $student->primaryPhoneNumber()?->e164_number === '+20'.ltrim(SmokeStudyFixtures::STUDENT_PHONE, '0'),
            'The registered phone number was not stored as the primary E.164 number; found '
                .($student->primaryPhoneNumber()?->e164_number ?? 'none').'.',
            $response,
        );

        $this->pass('Student registered');

        return $student;
    }

    /**
     * Email verification is what gates access, so an unverified account must be
     * refused at login rather than merely warned.
     */
    private function rejectLoginBeforeVerification(): void
    {
        $response = $this->api->post('/api/public/auth/login', [
            'email' => SmokeStudyFixtures::STUDENT_EMAIL,
            'password' => SmokeStudyFixtures::STUDENT_PASSWORD,
        ]);

        $this->assertStatus('Unverified login refused', $response, 403);
        $this->assertSame('Unverified login refused', 'EMAIL_NOT_VERIFIED', $response->json('code'), 'error code', $response);
        $this->pass('Unverified login refused');
    }

    private function verifyStudent(User $student): void
    {
        $pending = $student->emailVerificationOtps()
            ->where('purpose', OtpPurpose::EmailVerification)
            ->where('status', OtpStatus::Pending)
            ->latest('id')
            ->first();

        $this->assert(
            'Student verified',
            $pending !== null,
            'Registration did not leave a pending email verification code.',
        );

        $code = $this->otpInbox->latestVerificationCodeFor($student);

        $response = $this->api->post('/api/public/auth/verify-otp', [
            'email' => SmokeStudyFixtures::STUDENT_EMAIL,
            'code' => $code,
        ]);

        $this->assertStatus('Student verified', $response, 200);

        $student->refresh();

        $this->assert(
            'Student verified',
            $student->hasVerifiedEmail() && $student->status === AccountStatus::Active,
            sprintf(
                'Verification must verify the email and activate the account; found verified at %s, status "%s".',
                $student->email_verified_at?->toIso8601String() ?? 'null',
                $student->status?->value,
            ),
            $response,
        );

        $this->assert(
            'Student verified',
            $pending->refresh()->status === OtpStatus::Verified,
            'The consumed code was not marked verified; found "'.$pending->status?->value.'".',
            $response,
        );

        $this->pass('Student verified');
    }

    private function login(User $student): string
    {
        $response = $this->api->post('/api/public/auth/login', [
            'email' => SmokeStudyFixtures::STUDENT_EMAIL,
            'password' => SmokeStudyFixtures::STUDENT_PASSWORD,
        ]);

        $this->assertStatus('Student logged in', $response, 200);

        $token = $response->json('data.token');

        $this->assert(
            'Student logged in',
            is_string($token) && $token !== '',
            'Login returned no API token.',
            $response,
        );

        $this->assertSame('Student logged in', $student->getKey(), $response->json('data.user.id'), 'authenticated user id', $response);

        $this->assert(
            'Student logged in',
            PersonalAccessToken::query()
                ->where('tokenable_type', $student->getMorphClass())
                ->where('tokenable_id', $student->getKey())
                ->exists(),
            'No personal access token was persisted for the student.',
            $response,
        );

        $this->assert(
            'Student logged in',
            $student->refresh()->last_login_at !== null,
            'Login did not record last_login_at.',
            $response,
        );

        $this->pass('Student logged in');

        return $token;
    }

    private function retrieveProfile(User $student): void
    {
        $response = $this->api->get('/api/public/profile');

        $this->assertStatus('Profile retrieved', $response, 200);
        $this->assertSame('Profile retrieved', $student->getKey(), $response->json('data.id'), 'profile id', $response);
        $this->assertSame('Profile retrieved', SmokeStudyFixtures::STUDENT_EMAIL, $response->json('data.email'), 'profile email', $response);
        $this->assertSame('Profile retrieved', true, $response->json('data.is_email_verified'), 'profile verification flag', $response);
        $this->assertSame('Profile retrieved', AccountStatus::Active->value, $response->json('data.status'), 'profile status', $response);
        $this->pass('Profile retrieved');
    }

    // ── Study flow ────────────────────────────────────────────────────────────

    private function startStudySession(Subject $subject): int
    {
        $response = $this->api->post('/api/student/study/sessions', [
            'subject_id' => $subject->getKey(),
        ]);

        $this->assertStatus('Study session started', $response, 201);
        $this->assertSame('Study session started', $subject->getKey(), $response->json('data.subject_id'), 'session subject', $response);
        $this->assertSame('Study session started', true, $response->json('data.is_open'), 'session open flag', $response);
        $this->pass('Study session started');

        return (int) $response->json('data.id');
    }

    /**
     * The subject's entry point as the API reports it. There is no subjects
     * index or subject detail endpoint, so this — the resume state — is the only
     * place the API tells a student that a subject is theirs to study and where
     * in it they begin.
     */
    private function confirmSubjectEntryPoint(Subject $subject, Chapter $chapterOne): void
    {
        $response = $this->api->get("/api/student/study/subjects/{$subject->getKey()}/resume");

        $this->assertStatus('Subject visible', $response, 200);
        $this->assertSame('Subject visible', $subject->getKey(), $response->json('data.subject_id'), 'resume subject', $response);
        $this->assertSame('Subject visible', false, $response->json('data.has_position'), 'has_position before studying', $response);
        $this->assertSame('Subject visible', $chapterOne->getKey(), $response->json('data.chapter.id'), 'first accessible chapter', $response);
        $this->assertSame('Subject visible', null, $response->json('data.chapter_page'), 'resume page before studying', $response);
        $this->pass('Subject visible, starting at chapter 1');
    }

    private function confirmChapterLocked(Chapter $chapter): void
    {
        $response = $this->api->get("/api/student/study/chapters/{$chapter->getKey()}");

        $this->assertStatus('Chapter 2 locked', $response, 403);
        $this->assertSame('Chapter 2 locked', 'CHAPTER_LOCKED', $response->json('code'), 'error code', $response);

        $this->assert(
            'Chapter 2 locked',
            ! $this->access->canAccessChapter(
                User::query()->where('email', SmokeStudyFixtures::STUDENT_EMAIL)->first(),
                $chapter,
            ),
            'The access service considers chapter 2 reachable before chapter 1 is complete.',
            $response,
        );

        $this->pass('Chapter 2 locked before chapter 1 is completed');
    }

    private function confirmChapterUnlocked(Chapter $chapter): void
    {
        $response = $this->api->get("/api/student/study/chapters/{$chapter->getKey()}");

        $this->assertStatus('Chapter 2 unlocked', $response, 200);
        $this->assertSame('Chapter 2 unlocked', $chapter->getKey(), $response->json('data.id'), 'chapter id', $response);
        $this->assertSame('Chapter 2 unlocked', false, $response->json('data.is_completed'), 'completion flag', $response);
        $this->pass('Chapter 2 unlocked by completing chapter 1');
    }

    /**
     * Open a chapter, read every page through the API and mark each one read,
     * checking that reading progress is recounted as it goes.
     */
    private function studyChapter(Chapter $chapter, bool $annotate): void
    {
        $label = $this->chapterLabel($chapter);

        $response = $this->api->get("/api/student/study/chapters/{$chapter->getKey()}");

        $this->assertStatus("{$label} accessible", $response, 200);
        $this->assertSame("{$label} accessible", $chapter->title, $response->json('data.title'), 'chapter title', $response);
        $this->assertSame("{$label} accessible", 0, $response->json('data.reading_progress.completed_pages'), 'pages read before studying', $response);
        $this->assertSame("{$label} accessible", 3, $response->json('data.reading_progress.total_pages'), 'total pages', $response);
        $this->assertSame("{$label} accessible", 3, $response->json('data.quiz.question_count'), 'quiz question count', $response);
        $this->assertSame("{$label} accessible", $chapter->quiz->getKey(), $response->json('data.quiz.id'), 'chapter quiz id', $response);

        $pages = $response->data('pages');

        $this->assertSame("{$label} accessible", [1, 2, 3], array_column($pages, 'position'), 'page positions', $response);

        $this->pass("{$label} accessible");

        $expectedIds = $chapter->pages()->pluck('id')->all();

        $this->assertSame("{$label} content retrieved", $expectedIds, array_column($pages, 'id'), 'page order', $response);

        foreach ($pages as $index => $page) {
            $pageResponse = $this->api->get("/api/student/study/chapter-pages/{$page['id']}");

            $this->assertStatus("{$label} content retrieved", $pageResponse, 200);

            $content = $pageResponse->json('data.content');

            $this->assert(
                "{$label} content retrieved",
                is_string($content) && $content !== '',
                "Page {$page['id']} returned no content.",
                $pageResponse,
            );

            $this->assertSame(
                "{$label} content retrieved",
                $chapter->pages()->whereKey($page['id'])->value('content'),
                $content,
                "page {$page['id']} content",
                $pageResponse,
            );

            if ($annotate && $index === 0) {
                $this->annotatePage((int) $page['id'], $content);
            }

            $completed = $this->api->post("/api/student/study/chapter-pages/{$page['id']}/complete");

            $this->assertStatus("{$label} pages completed", $completed, 200);
            $this->assertSame("{$label} pages completed", true, $completed->json('data.page.is_completed'), 'page completion flag', $completed);
            $this->assertSame(
                "{$label} pages completed",
                $index + 1,
                $completed->json('data.reading_progress.completed_pages'),
                'recounted pages read',
                $completed,
            );
        }

        $this->pass("{$label} content retrieved and read");

        $final = $this->api->get("/api/student/study/chapters/{$chapter->getKey()}");

        $this->assertStatus("{$label} pages completed", $final, 200);
        $this->assertSame("{$label} pages completed", 100.0, (float) $final->json('data.reading_progress.percentage'), 'reading percentage', $final);
        $this->pass("{$label} reading progress is 100%");
    }

    /**
     * Highlight a passage and write a note against it, then read both back.
     */
    private function annotatePage(int $pageId, string $content): void
    {
        $passage = mb_substr(trim(strip_tags($content)), 0, 80);

        $highlight = $this->api->post("/api/student/study/chapter-pages/{$pageId}/highlights", [
            'selected_text' => $passage,
        ]);

        $this->assertStatus('Annotations created', $highlight, 201);
        $this->assertSame('Annotations created', $passage, $highlight->json('data.selected_text'), 'highlighted passage', $highlight);

        $note = $this->api->post("/api/student/study/chapter-pages/{$pageId}/notes", [
            'selected_text' => $passage,
            'content' => 'Smoke note: revise this before the chapter quiz.',
        ]);

        $this->assertStatus('Annotations created', $note, 201);

        $highlights = $this->api->get("/api/student/study/chapter-pages/{$pageId}/highlights");
        $notes = $this->api->get("/api/student/study/chapter-pages/{$pageId}/notes");

        $this->assertStatus('Annotations created', $highlights, 200);
        $this->assertStatus('Annotations created', $notes, 200);
        $this->assertSame('Annotations created', 1, count($highlights->data()), 'highlights on the page', $highlights);
        $this->assertSame('Annotations created', 1, count($notes->data()), 'notes on the page', $notes);

        $this->report['Highlight ID'] = $highlight->json('data.id');
        $this->report['Note ID'] = $note->json('data.id');

        $this->pass('Highlight and note created and read back');
    }

    /**
     * Take the chapter's quiz through the API, deliberately getting
     * `$wrongAnswers` of them wrong so the reported score has to be earned.
     *
     * @return int the attempt id
     */
    private function takeQuiz(Chapter $chapter, int $wrongAnswers): int
    {
        $label = $this->chapterLabel($chapter);
        $quiz = $chapter->quiz;

        $started = $this->api->post("/api/student/study/chapters/{$chapter->getKey()}/quiz/start");

        $this->assertStatus("{$label} quiz started", $started, 201);
        $this->assertSame("{$label} quiz started", 'Quiz attempt started.', $started->json('message'), 'start message', $started);
        $this->assertSame("{$label} quiz started", QuizAttemptStatus::InProgress->value, $started->json('data.status'), 'attempt status', $started);
        $this->assertSame("{$label} quiz started", $quiz->getKey(), $started->json('data.quiz_id'), 'attempt quiz', $started);
        $this->assertSame("{$label} quiz started", 3, $started->json('data.total_questions'), 'questions in the attempt', $started);
        $this->assertSame("{$label} quiz started", 0, $started->json('data.answered_questions'), 'answered questions', $started);

        $attemptId = (int) $started->json('data.id');
        $questions = $started->data('questions');

        $this->assertSame("{$label} quiz started", [1, 2, 3], array_column($questions, 'position'), 'question positions', $started);

        $quizQuestionIds = $quiz->questions()->pluck('id')->all();

        $this->assert(
            "{$label} quiz started",
            array_diff(array_column($questions, 'question_id'), $quizQuestionIds) === [],
            'The attempt holds questions that do not belong to this chapter\'s quiz.',
            $started,
        );

        foreach ($questions as $question) {
            $this->assert(
                "{$label} quiz started",
                $question['correct_option_key'] === null && $question['is_correct'] === null && count($question['options']) === 4,
                'An unanswered question must offer 4 options and reveal nothing about the answer.',
                $started,
            );
        }

        $this->pass("{$label} quiz started");

        // Walking away and coming back must land on the same attempt, same order.
        $resumed = $this->api->post("/api/student/study/chapters/{$chapter->getKey()}/quiz/start");

        $this->assertStatus("{$label} quiz resumed", $resumed, 200);
        $this->assertSame("{$label} quiz resumed", 'Quiz attempt resumed.', $resumed->json('message'), 'resume message', $resumed);
        $this->assertSame("{$label} quiz resumed", $attemptId, $resumed->json('data.id'), 'resumed attempt id', $resumed);
        $this->assertSame(
            "{$label} quiz resumed",
            array_column($questions, 'question_id'),
            array_column($resumed->data('questions'), 'question_id'),
            'resumed question order',
            $resumed,
        );
        $this->pass("{$label} quiz resumed as the same attempt");

        $expectedCorrect = 0;
        $attempt = QuizAttempt::query()->findOrFail($attemptId);

        foreach ($attempt->questions as $index => $attemptQuestion) {
            $snapshot = $attemptQuestion->snapshot();
            $correctKey = (string) $snapshot->correctOptionKey();
            $answerWrongly = $index < $wrongAnswers;

            $key = $answerWrongly
                ? $this->anyIncorrectOptionKey($attemptQuestion->question_snapshot, $correctKey)
                : $correctKey;

            $expectedCorrect += $answerWrongly ? 0 : 1;

            $answer = $this->api->post(
                "/api/student/study/quiz-attempts/{$attemptId}/questions/{$attemptQuestion->getKey()}/answer",
                ['option_key' => $key],
            );

            $this->assertStatus("{$label} answers submitted", $answer, 200);
            $this->assertSame("{$label} answers submitted", ! $answerWrongly, $answer->json('data.question.is_correct'), 'grading of answer '.($index + 1), $answer);
            $this->assertSame("{$label} answers submitted", $correctKey, $answer->json('data.question.correct_option_key'), 'disclosed correct option', $answer);
            $this->assertSame("{$label} answers submitted", $key, $answer->json('data.question.selected_option_key'), 'recorded selection', $answer);
            $this->assertSame("{$label} answers submitted", $index + 1, $answer->json('data.attempt.answered_questions'), 'running answered count', $answer);
            $this->assertSame("{$label} answers submitted", $expectedCorrect, $answer->json('data.attempt.correct_answers'), 'running correct count', $answer);

            $this->assert(
                "{$label} answers submitted",
                $attemptQuestion->refresh()->selected_option_key === $key
                    && $attemptQuestion->is_correct === ! $answerWrongly
                    && $attemptQuestion->answered_at !== null,
                'The submitted answer was not persisted on the attempt question row.',
                $answer,
            );

            // An answer is final: the first question doubles as the check.
            if ($index === 0 && $wrongAnswers < 3) {
                $repeat = $this->api->post(
                    "/api/student/study/quiz-attempts/{$attemptId}/questions/{$attemptQuestion->getKey()}/answer",
                    ['option_key' => $correctKey],
                );

                $this->assertStatus("{$label} answers are final", $repeat, 422);
                $this->assertSame("{$label} answers are final", 'QUESTION_ALREADY_ANSWERED', $repeat->json('code'), 'error code', $repeat);
                $this->pass("{$label} answers are final once submitted");
            }
        }

        $this->pass("{$label} answers submitted");

        $result = $this->api->get("/api/student/study/quiz-attempts/{$attemptId}");

        $this->assertStatus("{$label} result verified", $result, 200);
        $this->assertSame("{$label} result verified", QuizAttemptStatus::Completed->value, $result->json('data.status'), 'attempt status', $result);
        $this->assertSame("{$label} result verified", 3, $result->json('data.total_questions'), 'total questions', $result);
        $this->assertSame("{$label} result verified", 3, $result->json('data.answered_questions'), 'answered questions', $result);
        $this->assertSame("{$label} result verified", 3 - $wrongAnswers, $result->json('data.correct_answers'), 'correct answers', $result);
        $this->assertSame("{$label} result verified", $wrongAnswers, $result->json('data.incorrect_answers'), 'incorrect answers', $result);
        $this->assertSame("{$label} result verified", null, $result->json('data.current_question_id'), 'current question after completion', $result);

        $this->assert(
            "{$label} result verified",
            is_string($result->json('data.completed_at')),
            'A completed attempt must carry completed_at.',
            $result,
        );

        $this->pass(sprintf('%s result verified (%d/%d correct)', $label, 3 - $wrongAnswers, 3));

        return $attemptId;
    }

    private function confirmChapterCompleted(Chapter $chapter): void
    {
        $label = $this->chapterLabel($chapter);

        $response = $this->api->get("/api/student/study/chapters/{$chapter->getKey()}");

        $this->assertStatus("{$label} completed", $response, 200);
        $this->assertSame("{$label} completed", true, $response->json('data.is_completed'), 'completion flag', $response);

        $student = User::query()->where('email', SmokeStudyFixtures::STUDENT_EMAIL)->first();

        $this->assert(
            "{$label} completed",
            StudentChapterProgress::query()
                ->where('user_id', $student->getKey())
                ->where('chapter_id', $chapter->getKey())
                ->exists(),
            'Completing the quiz did not record chapter completion.',
            $response,
        );

        $this->pass("{$label} completed");
    }

    /**
     * Subject progress is derived, not stored, and there is no endpoint that
     * reports it, so it is verified against the application's own rule:
     * completed published chapters over published chapters.
     */
    private function confirmSubjectProgress(User $student, Subject $subject, int $expectedCompleted): void
    {
        $publishedIds = $subject->publishedChapters()->pluck('id')->all();

        $completed = StudentChapterProgress::query()
            ->where('user_id', $student->getKey())
            ->whereIn('chapter_id', $publishedIds)
            ->count();

        $this->assertSame('Subject progress verified', $expectedCompleted, $completed, 'completed chapters of the subject');

        $this->pass(sprintf(
            'Subject progress verified (%d/%d chapters, %.1f%%)',
            $completed,
            count($publishedIds),
            count($publishedIds) > 0 ? $completed / count($publishedIds) * 100 : 0.0,
        ));
    }

    private function confirmResumeMovedOn(Subject $subject, Chapter $chapterTwo): void
    {
        $response = $this->api->get("/api/student/study/subjects/{$subject->getKey()}/resume");

        $this->assertStatus('Resume state advanced', $response, 200);
        $this->assertSame('Resume state advanced', true, $response->json('data.has_position'), 'has_position after studying', $response);

        $this->assert(
            'Resume state advanced',
            is_string($response->json('data.last_active_at')),
            'A recorded position must carry last_active_at.',
            $response,
        );

        $this->assert(
            'Resume state advanced',
            $response->json('data.quiz_attempt') === null,
            'A completed attempt must not be offered as somewhere to resume into.',
            $response,
        );

        $student = User::query()->where('email', SmokeStudyFixtures::STUDENT_EMAIL)->first();

        $this->assert(
            'Resume state advanced',
            $this->access->currentAccessibleChapter($student, $subject)?->is($chapterTwo) === true,
            'Chapter 2 is not the chapter the student can study next.',
            $response,
        );

        $this->pass('Resume state advanced to chapter 2');
    }

    private function endStudySession(int $sessionId): void
    {
        $response = $this->api->post("/api/student/study/sessions/{$sessionId}/end");

        $this->assertStatus('Study session ended', $response, 200);
        $this->assertSame('Study session ended', false, $response->json('data.is_open'), 'session open flag', $response);

        $this->assert(
            'Study session ended',
            is_string($response->json('data.ended_at')) && is_int($response->json('data.duration_seconds')),
            'Ending a session must store ended_at and a duration.',
            $response,
        );

        $repeat = $this->api->post("/api/student/study/sessions/{$sessionId}/end");

        $this->assertStatus('Study session ended', $repeat, 422);
        $this->assertSame('Study session ended', 'STUDY_SESSION_ALREADY_ENDED', $repeat->json('code'), 'error code', $repeat);

        $this->pass('Study session ended and cannot be ended twice');
    }

    /**
     * Attempt history. There is no endpoint that lists a student's attempts, so
     * each attempt is fetched by id through the API it does have, and the stored
     * history is checked behind it.
     *
     * @param  list<int>  $attemptIds
     */
    private function confirmQuizHistory(User $student, array $attemptIds): void
    {
        foreach ($attemptIds as $attemptId) {
            $response = $this->api->get("/api/student/study/quiz-attempts/{$attemptId}");

            $this->assertStatus('Quiz history verified', $response, 200);
            $this->assertSame('Quiz history verified', QuizAttemptStatus::Completed->value, $response->json('data.status'), "attempt {$attemptId} status", $response);
            $this->assertSame('Quiz history verified', 3, count($response->data('questions')), "attempt {$attemptId} question rows", $response);
        }

        $stored = QuizAttempt::query()->where('user_id', $student->getKey())->get();

        $this->assertSame('Quiz history verified', 2, $stored->count(), 'persisted attempts for the student');

        foreach ($stored as $attempt) {
            $this->assert(
                'Quiz history verified',
                $attempt->questions()->count() === 3
                    && $attempt->questions()->answered()->count() === 3,
                "Attempt {$attempt->getKey()} did not persist 3 answered question rows.",
            );
        }

        $this->pass('Quiz history verified (2 completed attempts, 6 answered questions)');
    }

    /**
     * Study activity, as far as the application records it today: the session
     * that was opened and closed, the pages read, and the positions kept. There
     * is no statistics endpoint to read it back from.
     */
    private function confirmStudyActivity(User $student, Subject $subject): void
    {
        $sessions = $student->studySessions()->where('subject_id', $subject->getKey())->get();

        $this->assertSame('Study stats verified', 1, $sessions->count(), 'study sessions on the subject');

        $this->assert(
            'Study stats verified',
            $sessions->every(fn ($session): bool => $session->hasEnded() && $session->duration_seconds !== null),
            'A closed study session must carry a duration.',
        );

        $this->assertSame('Study stats verified', 6, $student->chapterPageProgress()->count(), 'pages marked read');
        $this->assertSame('Study stats verified', 2, $student->chapterProgress()->count(), 'chapters completed');
        $this->assertSame('Study stats verified', 1, $student->studyPositions()->count(), 'study positions kept');
        $this->assertSame('Study stats verified', 1, $student->highlights()->count(), 'highlights');
        $this->assertSame('Study stats verified', 1, $student->notes()->count(), 'notes');

        $this->pass('Study activity verified (6 pages read, 2 chapters completed, 1 session closed)');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * One wrong option key from the attempt question's own snapshot.
     *
     * @param  array<string, mixed>|null  $snapshot
     */
    private function anyIncorrectOptionKey(?array $snapshot, string $correctKey): string
    {
        foreach (($snapshot['options'] ?? []) as $option) {
            if ((string) $option['key'] !== $correctKey) {
                return (string) $option['key'];
            }
        }

        throw new SmokeFailure('Answers submitted', 'A snapshotted question offered no incorrect option to choose.');
    }

    private function chapterLabel(Chapter $chapter): string
    {
        return $chapter->title === SmokeStudyFixtures::CHAPTER_ONE_TITLE ? 'Chapter 1' : 'Chapter 2';
    }

    private function egyptCountryId(): int
    {
        $table = (string) config('world.migrations.countries.table_name', 'countries');

        $id = DB::table($table)->where('iso2', 'EG')->value('id');

        if ($id === null) {
            throw new SmokeFailure(
                'Smoke content prepared',
                "No country with ISO2 \"EG\" exists in `{$table}`. Registration needs a country; seed the world data first.",
            );
        }

        return (int) $id;
    }

    /**
     * The study schema the scenario needs. Missing tables mean the local
     * database is behind the branch, which is for the developer to fix with
     * `php artisan migrate` — this command never touches the schema.
     */
    private function assertSchemaIsReady(): void
    {
        $missing = array_values(array_filter(
            self::REQUIRED_TABLES,
            fn (string $table): bool => ! Schema::hasTable($table),
        ));

        $this->assert(
            'Database schema ready',
            $missing === [],
            'The database is missing '.implode(', ', $missing)
                .'. Run `php artisan migrate` and try again; this command does not migrate.',
        );

        $this->pass('Database schema ready');
    }

    private function environmentIsSafe(): bool
    {
        return ! app()->environment('production') && app()->environment(self::ALLOWED_ENVIRONMENTS);
    }

    private function assert(string $step, bool $condition, string $detail, ?SmokeApiResponse $response = null): void
    {
        if (! $condition) {
            throw new SmokeFailure($step, $detail, $response);
        }
    }

    private function assertStatus(string $step, SmokeApiResponse $response, int $expected): void
    {
        $this->assert(
            $step,
            $response->status === $expected,
            "Expected HTTP {$expected}, got {$response->status}.",
            $response,
        );
    }

    private function assertSame(string $step, mixed $expected, mixed $actual, string $what, ?SmokeApiResponse $response = null): void
    {
        $this->assert(
            $step,
            $expected === $actual,
            sprintf('Wrong %s: expected %s, got %s.', $what, $this->stringify($expected), $this->stringify($actual)),
            $response,
        );
    }

    private function stringify(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value),
        };
    }

    private function pass(string $label): void
    {
        $this->passes++;
        $this->line("<fg=green>[PASS]</> {$label}");
    }

    private function reportFailure(SmokeFailure $failure): void
    {
        $this->line("<fg=red>[FAIL]</> {$failure->step}");
        $this->newLine();
        $this->components->error($failure->getMessage());

        $response = $failure->response;

        if ($response !== null) {
            $this->line('  '.$response->describe());
            $this->line('  '.mb_substr($response->raw, 0, 2000));
        }

        $this->newLine();
        $this->line('<fg=red;options=bold>SMOKE TEST FAILED</>');
        $this->line('Smoke records created so far were left in place for inspection.');
    }

    private function printReport(): void
    {
        $this->newLine();

        foreach ($this->report as $label => $value) {
            $this->line(sprintf('  %-22s %s', $label.':', $value));
        }

        $this->newLine();
        $this->line('  Marker: all smoke content is named with '.SmokeStudyFixtures::MARKER.'.');
    }
}
