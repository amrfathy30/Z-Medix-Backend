<?php

namespace App\Support\Smoke;

use App\Enums\ContentStatus;
use App\Enums\QuizDifficulty;
use App\Models\Chapter;
use App\Models\PhoneNumber;
use App\Models\Subject;
use App\Models\User;
use App\Services\Learning\ChapterService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;

/**
 * The admin-owned study content a smoke run needs, and the removal of whatever
 * a previous run left behind.
 *
 * Content is built through the same path the admin panel uses — a chapter and
 * its quiz in one transaction via {@see ChapterService::createWithQuiz()}, and
 * publishing only once the chapter satisfies {@see Chapter::isPublishable()} —
 * so the fixture cannot be in a shape the application would refuse to produce.
 *
 * Nothing a student creates is built here. Progress, attempts, answers,
 * positions, sessions and annotations exist only because the smoke run drove the
 * API.
 *
 * Everything is named with {@see self::MARKER}, which is what makes cleanup
 * narrow: only rows carrying the marker, plus the one fixed smoke account, are
 * ever removed. No table is truncated and no unrelated local data is touched.
 */
class SmokeStudyFixtures
{
    /** Every record this class creates carries this marker in its name. */
    public const MARKER = '[SMOKE]';

    public const SUBJECT_NAME = self::MARKER.' Internal Medicine';

    public const CHAPTER_ONE_TITLE = self::MARKER.' Cardiology Basics';

    public const CHAPTER_TWO_TITLE = self::MARKER.' Respiratory Basics';

    public const STUDENT_EMAIL = 'smoke.student@example.com';

    public const STUDENT_NAME = self::MARKER.' Smoke Student';

    /** Local-only fixed password, so a run is reproducible. */
    public const STUDENT_PASSWORD = 'SmokeLocal#2026';

    /** Egyptian mobile number reserved for the smoke account. */
    public const STUDENT_PHONE = '01010000111';

    public function __construct(private ChapterService $chapters) {}

    /**
     * Remove the previous run's records, and nothing else.
     *
     * The smoke subject is force-deleted, which the schema cascades through
     * chapters, pages, quizzes, questions, options, attempts, attempt questions,
     * study sessions, positions, highlights, notes and chapter progress. The
     * smoke student is force-deleted the same way; their phone numbers and API
     * tokens are polymorphic, so they carry no cascade and are removed here.
     *
     * @return array<string, int> rows removed, per kind
     */
    public function cleanPreviousRun(): array
    {
        return DB::transaction(function (): array {
            $students = User::withTrashed()->where('email', self::STUDENT_EMAIL)->get();

            $tokens = 0;
            $phones = 0;

            foreach ($students as $student) {
                $tokens += PersonalAccessToken::query()
                    ->where('tokenable_type', $student->getMorphClass())
                    ->where('tokenable_id', $student->getKey())
                    ->forceDelete();

                $phones += PhoneNumber::withTrashed()
                    ->where('owner_type', $student->getMorphClass())
                    ->where('owner_id', $student->getKey())
                    ->forceDelete();
            }

            $subjects = 0;

            foreach (Subject::withTrashed()->where('name', 'like', self::MARKER.'%')->get() as $subject) {
                $subject->forceDelete();
                $subjects++;
            }

            $removedStudents = 0;

            foreach ($students as $student) {
                $student->forceDelete();
                $removedStudents++;
            }

            return [
                'subjects' => $subjects,
                'students' => $removedStudents,
                'phone_numbers' => $phones,
                'api_tokens' => $tokens,
            ];
        });
    }

    /**
     * Build the smoke subject: one published subject, two published chapters in
     * order, each with study pages and a quiz holding answerable questions.
     */
    public function build(): Subject
    {
        /** @var Subject $subject */
        $subject = Subject::query()->create([
            'name' => self::SUBJECT_NAME,
            'status' => ContentStatus::Published,
        ]);

        $this->addChapter($subject, self::CHAPTER_ONE_TITLE, QuizDifficulty::Easy, $this->cardiologyPages(), $this->cardiologyQuestions());
        $this->addChapter($subject, self::CHAPTER_TWO_TITLE, QuizDifficulty::Medium, $this->respiratoryPages(), $this->respiratoryQuestions());

        return $subject->refresh();
    }

    /**
     * One chapter, created the supported way and published only once its quiz
     * can carry a student.
     *
     * @param  list<string>  $pages
     * @param  list<array{question: string, options: list<string>, correct: int}>  $questions
     */
    private function addChapter(
        Subject $subject,
        string $title,
        QuizDifficulty $difficulty,
        array $pages,
        array $questions,
    ): Chapter {
        $chapter = $this->chapters->createWithQuiz(
            $subject,
            ['title' => $title],
            ['title' => ChapterService::defaultQuizTitle($title), 'difficulty' => $difficulty],
        );

        foreach ($pages as $content) {
            $chapter->pages()->create(['content' => $content]);
        }

        $quiz = $chapter->quiz;

        foreach ($questions as $definition) {
            $question = $quiz->questions()->create(['question' => $definition['question']]);

            foreach ($definition['options'] as $index => $optionText) {
                $question->options()->create([
                    'option_text' => $optionText,
                    'is_correct' => $index === $definition['correct'],
                    'order' => $index + 1,
                ]);
            }
        }

        $chapter->refresh();

        // The application's own publishing rule, applied rather than assumed:
        // a chapter with an empty quiz is not study content.
        if (! $chapter->isPublishable()) {
            throw new RuntimeException("Smoke chapter \"{$title}\" is not publishable: its quiz holds no questions.");
        }

        $chapter->update(['status' => ContentStatus::Published]);

        return $chapter->refresh();
    }

    /**
     * @return list<string>
     */
    private function cardiologyPages(): array
    {
        return [
            '<h2>The cardiac cycle</h2><p>Each heartbeat is one cardiac cycle: atrial systole fills the ventricles, '
                .'ventricular systole ejects blood into the aorta and pulmonary artery, and diastole lets the chambers refill. '
                .'At a resting rate of 70 beats per minute a single cycle lasts roughly 0.8 seconds, of which diastole takes about two thirds.</p>',
            '<h2>Heart sounds</h2><p>The first heart sound, S1, is the closure of the mitral and tricuspid valves at the start of '
                .'ventricular systole. The second, S2, is the closure of the aortic and pulmonary valves as systole ends. '
                .'A murmur is turbulent flow across a valve, and its timing within the cycle is the first clue to which valve is involved.</p>',
            '<h2>Cardiac output</h2><p>Cardiac output is stroke volume multiplied by heart rate, normally about 5 litres per minute at rest. '
                .'Stroke volume depends on preload, afterload and contractility; the Frank-Starling relationship describes how a greater '
                .'end-diastolic volume stretches the myocardium and increases the force of the following contraction.</p>',
        ];
    }

    /**
     * @return list<array{question: string, options: list<string>, correct: int}>
     */
    private function cardiologyQuestions(): array
    {
        return [
            [
                'question' => 'Which valves close to produce the first heart sound (S1)?',
                'options' => [
                    'The aortic and pulmonary valves',
                    'The mitral and tricuspid valves',
                    'The mitral and aortic valves',
                    'The tricuspid and pulmonary valves',
                ],
                'correct' => 1,
            ],
            [
                'question' => 'Cardiac output is calculated as which of the following?',
                'options' => [
                    'Stroke volume multiplied by heart rate',
                    'Heart rate divided by stroke volume',
                    'Mean arterial pressure multiplied by heart rate',
                    'End-diastolic volume minus end-systolic volume',
                ],
                'correct' => 0,
            ],
            [
                'question' => 'At a resting heart rate of 70 beats per minute, which phase occupies most of the cardiac cycle?',
                'options' => [
                    'Atrial systole',
                    'Ventricular systole',
                    'Diastole',
                    'Isovolumetric contraction',
                ],
                'correct' => 2,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function respiratoryPages(): array
    {
        return [
            '<h2>Lung volumes</h2><p>Tidal volume is the air moved in a quiet breath, about 500 millilitres in an adult. '
                .'Vital capacity is the largest volume that can be exhaled after a maximal inspiration, while residual volume is the air '
                .'that cannot be expelled and therefore cannot be measured by spirometry alone.</p>',
            '<h2>Gas exchange</h2><p>Oxygen and carbon dioxide cross the alveolar membrane by passive diffusion down their partial pressure '
                .'gradients. Diffusion depends on the surface area available, the thickness of the membrane and the gradient itself, '
                .'which is why both emphysema and pulmonary oedema impair exchange although they damage different parts of the barrier.</p>',
            '<h2>Control of breathing</h2><p>Central chemoreceptors in the medulla respond to the rise in carbon dioxide, sensed as a fall in '
                .'cerebrospinal fluid pH, and are the main minute-to-minute driver of ventilation. Peripheral chemoreceptors in the carotid '
                .'and aortic bodies respond to a falling arterial oxygen tension and matter most in hypoxia.</p>',
        ];
    }

    /**
     * @return list<array{question: string, options: list<string>, correct: int}>
     */
    private function respiratoryQuestions(): array
    {
        return [
            [
                'question' => 'Which lung volume cannot be measured by spirometry?',
                'options' => [
                    'Tidal volume',
                    'Vital capacity',
                    'Inspiratory reserve volume',
                    'Residual volume',
                ],
                'correct' => 3,
            ],
            [
                'question' => 'By which mechanism do oxygen and carbon dioxide cross the alveolar membrane?',
                'options' => [
                    'Passive diffusion down a partial pressure gradient',
                    'Active transport driven by ATP',
                    'Facilitated transport by carrier proteins',
                    'Bulk flow driven by hydrostatic pressure',
                ],
                'correct' => 0,
            ],
            [
                'question' => 'What is the main stimulus for the central chemoreceptors that drive resting ventilation?',
                'options' => [
                    'A fall in arterial oxygen tension',
                    'A rise in carbon dioxide, sensed as a fall in cerebrospinal fluid pH',
                    'A rise in arterial potassium',
                    'A fall in pulmonary artery pressure',
                ],
                'correct' => 1,
            ],
        ];
    }
}
