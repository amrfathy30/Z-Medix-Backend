<?php

namespace App\Support\Smoke\Fellowship\Scenarios;

use App\Enums\AccountStatus;
use App\Enums\FellowshipQuestionStatusFilter;
use App\Enums\FellowshipSessionStatus;
use App\Enums\OtpPurpose;
use App\Enums\OtpStatus;
use App\Models\FellowshipQuizSession;
use App\Models\User;
use App\Support\Smoke\Fellowship\FellowshipSmokeContext;
use App\Support\Smoke\Fellowship\FellowshipSmokeFixtures;
use App\Support\Smoke\SmokeApiResponse;
use App\Support\Smoke\SmokeOtpInbox;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The student side, driven entirely through the authenticated API.
 *
 * Registration, verification and login are the application's own endpoints, and
 * every subsequent request carries the token login returned — so routing, the
 * `api` middleware group, `auth:sanctum`, the form requests and the API
 * resources all run exactly as they do for the real client. Nothing forges a
 * user context and nothing calls a service directly.
 *
 * Two students are registered. The second exists only to prove that a session
 * belongs to the student who generated it: every read and write against someone
 * else's session must come back as not found.
 */
class FellowshipStudentFlowScenario
{
    public function __construct(private SmokeOtpInbox $otpInbox) {}

    public function run(FellowshipSmokeContext $context): void
    {
        $fellowship = $context->fellowship;

        if ($fellowship === null) {
            $context->ledger->skip('Student flow', 'No Fellowship was created by the admin scenario.');

            return;
        }

        $this->registerStudents($context);
        $this->rejectUnauthenticated($context);
        $this->readFellowshipList($context);
        $this->readFellowshipDashboard($context);
        $this->readFilters($context);
        $this->exerciseQuestionSelection($context);
        $this->runFullSession($context);
        $this->enforceSequentialAnswering($context);
        $this->enforceOwnership($context);
        $this->readSessionHistory($context);
    }

    // ── Authentication ────────────────────────────────────────────────────────

    /**
     * Register and verify both students through the real endpoints, including
     * the refusal of a login before the email is verified.
     */
    private function registerStudents(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $ledger->group('Student · Authentication');

        $this->otpInbox->intercept();

        $countryId = $this->egyptCountryId();

        if ($countryId === null) {
            $ledger->skip('Students registered', 'No country with ISO2 "EG" exists; registration needs one.');

            return;
        }

        $context->student = $this->registerOne(
            $context,
            FellowshipSmokeFixtures::STUDENT_NAME,
            FellowshipSmokeFixtures::STUDENT_EMAIL,
            FellowshipSmokeFixtures::STUDENT_PHONE,
            $countryId,
            primary: true,
        );

        $context->otherStudent = $this->registerOne(
            $context,
            FellowshipSmokeFixtures::OTHER_STUDENT_NAME,
            FellowshipSmokeFixtures::OTHER_STUDENT_EMAIL,
            FellowshipSmokeFixtures::OTHER_STUDENT_PHONE,
            $countryId,
            primary: false,
        );

        if ($context->student !== null) {
            $context->studentToken = $this->login($context, $context->student, FellowshipSmokeFixtures::STUDENT_EMAIL, primary: true);
            $ledger->note('Smoke Student ID', $context->student->getKey());
            $ledger->note('Smoke Student email', $context->student->email);
        }

        if ($context->otherStudent !== null) {
            $context->otherStudentToken = $this->login($context, $context->otherStudent, FellowshipSmokeFixtures::OTHER_STUDENT_EMAIL, primary: false);
            $ledger->note('Second Student ID', $context->otherStudent->getKey());
        }
    }

    /**
     * One student, from registration through verification.
     *
     * The verification code is read from the notification the registration
     * request actually sent, because the stored row holds only a hash. The real
     * `verify-otp` endpoint then consumes it, so the pending row, the attempt
     * counter and the activation all run unchanged — nothing about verification
     * is bypassed, and the code itself is never printed.
     */
    private function registerOne(
        FellowshipSmokeContext $context,
        string $name,
        string $email,
        string $phone,
        int $countryId,
        bool $primary,
    ): ?User {
        $ledger = $context->ledger;
        $label = $primary ? 'Student' : 'Second student';

        $context->asGuest();

        $response = $context->api->post('/api/public/auth/register', [
            'full_name' => $name,
            'email' => $email,
            'phone' => $phone,
            'country_id' => $countryId,
            'password' => FellowshipSmokeFixtures::STUDENT_PASSWORD,
            'password_confirmation' => FellowshipSmokeFixtures::STUDENT_PASSWORD,
        ]);

        if (! $ledger->same($label.' registered (HTTP 201)', 201, $response->status, $response)) {
            return null;
        }

        $student = User::query()->where('email', $email)->first();

        if (! $ledger->check($label.' row written', $student !== null, 'No users row for '.$email, $response)) {
            return null;
        }

        $ledger->check(
            $label.' starts pending and unverified',
            $student->status === AccountStatus::Pending && ! $student->hasVerifiedEmail(),
            sprintf('status "%s", verified at %s', $student->status?->value, $student->email_verified_at?->toIso8601String() ?? 'null'),
        );

        if ($primary) {
            // Verification is what gates access, so an unverified login must be
            // refused rather than merely warned about.
            $refused = $context->api->post('/api/public/auth/login', [
                'email' => $email,
                'password' => FellowshipSmokeFixtures::STUDENT_PASSWORD,
            ]);

            $ledger->same('Login before verification is refused (HTTP 403)', 403, $refused->status, $refused);
            $ledger->same('Login before verification says why', 'EMAIL_NOT_VERIFIED', $refused->json('code'), $refused);
        }

        $pending = $student->emailVerificationOtps()
            ->where('purpose', OtpPurpose::EmailVerification)
            ->where('status', OtpStatus::Pending)
            ->latest('id')
            ->first();

        if (! $ledger->check($label.' has a pending verification code', $pending !== null, 'Registration left no pending OTP row.')) {
            return $student;
        }

        $code = $ledger->attempt($label.' verification code retrieved', fn (): string => $this->otpInbox->latestVerificationCodeFor($student));

        if (! is_string($code)) {
            return $student;
        }

        $verified = $context->api->post('/api/public/auth/verify-otp', [
            'email' => $email,
            'code' => $code,
        ]);

        $ledger->same($label.' verified (HTTP 200)', 200, $verified->status, $verified);

        $student->refresh();

        $ledger->check(
            $label.' is active and verified after verification',
            $student->hasVerifiedEmail() && $student->status === AccountStatus::Active,
            sprintf('verified at %s, status "%s"', $student->email_verified_at?->toIso8601String() ?? 'null', $student->status?->value),
        );

        $ledger->same($label.'\'s code was consumed', OtpStatus::Verified, $pending->refresh()->status);

        return $student;
    }

    private function login(FellowshipSmokeContext $context, User $student, string $email, bool $primary): ?string
    {
        $ledger = $context->ledger;
        $label = $primary ? 'Student' : 'Second student';

        $context->asGuest();

        $response = $context->api->post('/api/public/auth/login', [
            'email' => $email,
            'password' => FellowshipSmokeFixtures::STUDENT_PASSWORD,
        ]);

        if (! $ledger->same($label.' logged in (HTTP 200)', 200, $response->status, $response)) {
            return null;
        }

        $token = $response->json('data.token');

        $ledger->check($label.' received an API token', is_string($token) && $token !== '', 'Login returned no token.', $response);
        $ledger->same($label.' token belongs to the right account', $student->getKey(), $response->json('data.user.id'), $response);

        $ledger->check(
            $label.'\'s token was persisted',
            PersonalAccessToken::query()
                ->where('tokenable_type', $student->getMorphClass())
                ->where('tokenable_id', $student->getKey())
                ->exists(),
            'No personal access token row was written.',
        );

        return is_string($token) ? $token : null;
    }

    /**
     * Every Fellowship endpoint sits behind `auth:sanctum`, so an unauthenticated
     * request must be refused rather than answered anonymously.
     */
    private function rejectUnauthenticated(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $fellowship = $context->fellowship;

        if ($fellowship === null) {
            return;
        }

        $context->asGuest();

        $id = $fellowship->getKey();

        foreach ([
            'GET /api/student/fellowships' => fn (): SmokeApiResponse => $context->api->get('/api/student/fellowships'),
            'GET /api/student/fellowships/{id}' => fn (): SmokeApiResponse => $context->api->get('/api/student/fellowships/'.$id),
            'GET /api/student/fellowships/{id}/filters' => fn (): SmokeApiResponse => $context->api->get('/api/student/fellowships/'.$id.'/filters'),
            'POST /api/student/fellowships/{id}/available-questions' => fn (): SmokeApiResponse => $context->api->post('/api/student/fellowships/'.$id.'/available-questions', ['title' => 'x', 'question_count' => 1]),
            'GET /api/student/fellowships/{id}/sessions' => fn (): SmokeApiResponse => $context->api->get('/api/student/fellowships/'.$id.'/sessions'),
            'POST /api/student/fellowships/{id}/sessions' => fn (): SmokeApiResponse => $context->api->post('/api/student/fellowships/'.$id.'/sessions', ['title' => 'x', 'question_count' => 1]),
        ] as $label => $call) {
            $response = $call();

            $ledger->same('Unauthenticated '.$label.' is refused (401)', 401, $response->status, $response);
        }
    }

    // ── The Question Bank screen ──────────────────────────────────────────────

    private function readFellowshipList(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $ledger->group('Student · Fellowship list');

        if (! $context->asStudent()) {
            $ledger->skip('Fellowship list read', 'The student has no token.');

            return;
        }

        $fellowship = $context->fellowship;
        $response = $context->api->get('/api/student/fellowships');

        if (! $ledger->same('GET /fellowships returns 200', 200, $response->status, $response)) {
            return;
        }

        $items = $response->data();

        $ledger->check('The Fellowship list is a data array', array_is_list($items), 'Envelope was: '.mb_substr($response->raw, 0, 300));

        $mine = null;

        foreach ($items as $item) {
            if (($item['id'] ?? null) === $fellowship?->getKey()) {
                $mine = $item;
            }
        }

        if (! $ledger->check('The smoke Fellowship is listed', $mine !== null, 'Listed ids: '.implode(', ', array_column($items, 'id')), $response)) {
            return;
        }

        $ledger->same('The listed Fellowship carries its name', $fellowship?->name, $mine['name'] ?? null, $response);
        $ledger->same(
            'The listed Fellowship reports its question total',
            $fellowship?->questions()->count(),
            $mine['total_questions'] ?? null,
            $response,
        );
        $ledger->check(
            'The listed Fellowship carries an average_score key',
            array_key_exists('average_score', $mine),
            'Keys present: '.implode(', ', array_keys($mine)),
            $response,
        );
        $ledger->same(
            'A student with no answers has a null average score rather than zero',
            null,
            $mine['average_score'],
            $response,
        );
        $ledger->check(
            'The listed Fellowship carries its Performance Topic values',
            is_array($mine['performance_topics'] ?? null) && count($mine['performance_topics']) === 3,
            'performance_topics was: '.json_encode($mine['performance_topics'] ?? null),
            $response,
        );

        // An inactive Fellowship must not be listed, and must not be reachable
        // by id either.
        $other = $context->otherFellowship;

        if ($other !== null) {
            $other->update(['is_active' => false]);

            $afterDeactivation = $context->api->get('/api/student/fellowships');
            $listedIds = array_column($afterDeactivation->data(), 'id');

            $ledger->check(
                'An inactive Fellowship is not listed',
                ! in_array($other->getKey(), $listedIds, true),
                'The inactive Fellowship is still listed.',
                $afterDeactivation,
            );

            $direct = $context->api->get('/api/student/fellowships/'.$other->getKey());

            $ledger->same('An inactive Fellowship is not reachable by id (404)', 404, $direct->status, $direct);
            $ledger->same('An inactive Fellowship says why', 'FELLOWSHIP_UNAVAILABLE', $direct->json('code'), $direct);

            $other->update(['is_active' => true]);
        }
    }

    private function readFellowshipDashboard(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $ledger->group('Student · Fellowship dashboard');

        if (! $context->asStudent() || $context->fellowship === null) {
            $ledger->skip('Fellowship dashboard read', 'No authenticated student or Fellowship.');

            return;
        }

        $response = $context->api->get('/api/student/fellowships/'.$context->fellowship->getKey());

        if (! $ledger->same('GET /fellowships/{id} returns 200', 200, $response->status, $response)) {
            return;
        }

        $data = $response->data();

        foreach ([
            'id', 'name', 'total_questions', 'study_time_seconds', 'average_score',
            'topics_completed', 'day_streak', 'daily_study_time', 'quiz_performance',
            'topic_performance', 'recent_activity', 'performance_topics',
        ] as $key) {
            $ledger->check(
                'The dashboard carries "'.$key.'"',
                array_key_exists($key, $data),
                'Keys present: '.implode(', ', array_keys($data)),
                $response,
            );
        }

        $ledger->same('A fresh dashboard reports no study time', 0, $data['study_time_seconds'] ?? null, $response);
        $ledger->same('A fresh dashboard reports a null average score', null, $data['average_score'], $response);
        $ledger->same('A fresh dashboard reports no topics completed', 0, $data['topics_completed'] ?? null, $response);
        $ledger->same('A fresh dashboard reports no day streak', 0, $data['day_streak'] ?? null, $response);
        $ledger->same('A fresh dashboard reports no recent activity', [], $data['recent_activity'] ?? null, $response);

        $ledger->check(
            'Quiz Performance reports every difficulty even when untouched',
            is_array($data['quiz_performance'] ?? null) && count($data['quiz_performance']) === 3,
            'quiz_performance was: '.json_encode($data['quiz_performance'] ?? null),
            $response,
        );

        $ledger->check(
            'Daily Study Time returns a fixed seven-day window',
            is_array($data['daily_study_time'] ?? null) && count($data['daily_study_time']) === 7,
            'daily_study_time had '.count($data['daily_study_time'] ?? []).' entries.',
            $response,
        );
    }

    private function readFilters(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $ledger->group('Student · Filters');

        if (! $context->asStudent() || $context->fellowship === null) {
            $ledger->skip('Filters read', 'No authenticated student or Fellowship.');

            return;
        }

        $fellowship = $context->fellowship;
        $response = $context->api->get('/api/student/fellowships/'.$fellowship->getKey().'/filters');

        if (! $ledger->same('GET /fellowships/{id}/filters returns 200', 200, $response->status, $response)) {
            return;
        }

        $data = $response->data();
        $filters = $data['filters'] ?? [];

        $names = array_column($filters, 'name');
        sort($names);

        $ledger->same(
            'The filters endpoint offers every filter the imports created',
            ['Clinical Setting', 'Exam', 'Source', 'System', 'Year'],
            $names,
            $response,
        );

        $performance = null;

        foreach ($filters as $filter) {
            if (($filter['is_performance'] ?? false) === true) {
                $performance = $filter;
            }
        }

        $ledger->same('Exam is flagged as the Performance Topic filter', 'Exam', $performance['name'] ?? null, $response);

        $ledger->same(
            'The filters endpoint offers the three difficulties',
            ['easy', 'medium', 'hard'],
            $data['difficulties'] ?? null,
            $response,
        );

        $ledger->same(
            'The filters endpoint offers the three question statuses',
            ['all', 'answered', 'unanswered'],
            $data['question_statuses'] ?? null,
            $response,
        );

        $ledger->same(
            'The filters endpoint reports the Fellowship question total',
            $fellowship->questions()->count(),
            $data['total_questions'] ?? null,
            $response,
        );

        $ledger->check(
            'The filters endpoint reports the advisory per-question limit',
            is_int($data['question_time_limit_seconds'] ?? null) && $data['question_time_limit_seconds'] > 0,
            'question_time_limit_seconds was: '.json_encode($data['question_time_limit_seconds'] ?? null),
            $response,
        );

        // Every value offered must belong to this Fellowship.
        $offeredValueIds = [];

        foreach ($filters as $filter) {
            foreach ($filter['values'] ?? [] as $value) {
                $offeredValueIds[] = (int) $value['id'];
            }
        }

        $foreign = (int) DB::table('fellowship_filter_values as v')
            ->join('fellowship_filters as f', 'f.id', '=', 'v.fellowship_filter_id')
            ->whereIn('v.id', $offeredValueIds === [] ? [0] : $offeredValueIds)
            ->where('f.fellowship_id', '<>', $fellowship->getKey())
            ->count();

        $ledger->same('Every offered filter value belongs to this Fellowship', 0, $foreign, $response);
        $ledger->note('Filter values offered to the student', count($offeredValueIds));
    }

    // ── Question selection ────────────────────────────────────────────────────

    /**
     * The filter algebra, checked against the database rather than against the
     * service that produced it.
     *
     * Each case asks the real `available-questions` endpoint for a count, and
     * compares it with a count computed here in SQL from the same rules: values
     * within one filter are alternatives, different filters all have to hold,
     * and a filter left alone restricts nothing. A test that merely re-ran the
     * pool service would agree with itself whatever it did.
     */
    private function exerciseQuestionSelection(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $ledger->group('Student · Question selection');

        if (! $context->asStudent() || $context->fellowship === null) {
            $ledger->skip('Question selection', 'No authenticated student or Fellowship.');

            return;
        }

        $fellowship = $context->fellowship;
        $id = $fellowship->getKey();

        $filters = $fellowship->filters()->with('values')->get()->keyBy('name');
        $exam = $filters->get('Exam');
        $system = $filters->get('System');
        $year = $filters->get('Year');

        $examValues = $exam?->values->pluck('id', 'value')->all() ?? [];
        $systemValues = $system?->values->pluck('id', 'value')->all() ?? [];
        $yearValues = $year?->values->pluck('id', 'value')->all() ?? [];

        $mrcp = $examValues['MRCP'] ?? null;
        $mrcs = $examValues['MRCS'] ?? null;
        $cardiology = $systemValues['Cardiology'] ?? null;
        $respiratory = $systemValues['Respiratory'] ?? null;
        $year2024 = $yearValues['2024'] ?? null;

        $cases = [
            'No filters at all' => [],
            'One Performance Topic (MRCP)' => ['filters' => [$exam?->id => array_filter([$mrcp])]],
            'Two Performance Topics (MRCP or MRCS)' => ['filters' => [$exam?->id => array_filter([$mrcp, $mrcs])]],
            'One dynamic filter (System: Cardiology)' => ['filters' => [$system?->id => array_filter([$cardiology])]],
            'OR inside one dynamic filter (Cardiology or Respiratory)' => ['filters' => [$system?->id => array_filter([$cardiology, $respiratory])]],
            'AND across two filters (MRCP and Cardiology)' => ['filters' => array_filter([
                $exam?->id => array_filter([$mrcp]),
                $system?->id => array_filter([$cardiology]),
            ])],
            'AND across three filters (MRCP, Cardiology, 2024)' => ['filters' => array_filter([
                $exam?->id => array_filter([$mrcp]),
                $system?->id => array_filter([$cardiology]),
                $year?->id => array_filter([$year2024]),
            ])],
            'Difficulty Easy only' => ['difficulties' => ['easy']],
            'Difficulty Medium only' => ['difficulties' => ['medium']],
            'Difficulty Hard only' => ['difficulties' => ['hard']],
            'Two difficulties (Easy or Hard)' => ['difficulties' => ['easy', 'hard']],
            'All three difficulties' => ['difficulties' => ['easy', 'medium', 'hard']],
            'Question status All' => ['question_status' => 'all'],
            'Question status Unanswered' => ['question_status' => 'unanswered'],
            'Question status Answered' => ['question_status' => 'answered'],
            'Topic and difficulty combined' => array_filter([
                'filters' => array_filter([$exam?->id => array_filter([$mrcp])]),
                'difficulties' => ['hard'],
            ]),
            'A filter present but with nothing chosen restricts nothing' => ['filters' => [$exam?->id => []]],
        ];

        $counts = [];

        foreach ($cases as $label => $criteria) {
            $response = $context->api->post('/api/student/fellowships/'.$id.'/available-questions', [
                'title' => 'probe',
                'question_count' => 1,
                ...$criteria,
            ]);

            if (! $ledger->same('Availability · '.$label.' returns 200', 200, $response->status, $response)) {
                continue;
            }

            $reported = $response->json('data.available_questions');
            $expected = $this->expectedPoolCount($context, $criteria);

            $counts[$label] = (int) $reported;

            $ledger->same('Availability · '.$label, $expected, $reported, $response);
        }

        // Relations that must hold whatever the data happens to be.
        if (isset($counts['No filters at all'])) {
            $total = $counts['No filters at all'];

            $ledger->same(
                'With no filters the pool is the whole answerable bank',
                $this->answerableQuestionCount($id),
                $total,
            );

            foreach ($counts as $label => $count) {
                $ledger->check(
                    'Narrowing never widens the pool · '.$label,
                    $count <= $total,
                    sprintf('%d exceeds the unfiltered %d.', $count, $total),
                );
            }

            $ledger->same(
                'Question status All equals no filter at all',
                $total,
                $counts['Question status All'] ?? -1,
            );

            $ledger->same(
                'A filter with nothing chosen equals no filter at all',
                $total,
                $counts['A filter present but with nothing chosen restricts nothing'] ?? -1,
            );

            $ledger->same(
                'The three difficulties together cover the whole bank',
                $total,
                $counts['All three difficulties'] ?? -1,
            );

            $easy = $counts['Difficulty Easy only'] ?? 0;
            $medium = $counts['Difficulty Medium only'] ?? 0;
            $hard = $counts['Difficulty Hard only'] ?? 0;

            $ledger->same('The difficulties partition the bank', $total, $easy + $medium + $hard);
            $ledger->same('Two difficulties are the sum of their parts', $easy + $hard, $counts['Two difficulties (Easy or Hard)'] ?? -1);
        }

        if (isset($counts['One dynamic filter (System: Cardiology)'], $counts['OR inside one dynamic filter (Cardiology or Respiratory)'])) {
            $ledger->check(
                'OR within one filter widens that filter\'s pool',
                $counts['OR inside one dynamic filter (Cardiology or Respiratory)'] >= $counts['One dynamic filter (System: Cardiology)'],
                'The two-value selection returned fewer than the one-value selection.',
            );
        }

        if (isset($counts['AND across two filters (MRCP and Cardiology)'], $counts['One Performance Topic (MRCP)'], $counts['One dynamic filter (System: Cardiology)'])) {
            $ledger->check(
                'AND across filters never exceeds either side',
                $counts['AND across two filters (MRCP and Cardiology)'] <= min(
                    $counts['One Performance Topic (MRCP)'],
                    $counts['One dynamic filter (System: Cardiology)'],
                ),
                'The intersection was larger than one of its sides.',
            );
        }

        if (isset($counts['Question status Answered'])) {
            $ledger->same(
                'A student who has answered nothing has an empty Answered pool',
                0,
                $counts['Question status Answered'],
            );
        }

        // An empty matching pool, and a request for more than exists, must both
        // report the real availability and create nothing.
        $this->refuseImpossibleGeneration($context, $counts['No filters at all'] ?? 0);
    }

    /**
     * Ask for more questions than exist, and for a combination that matches
     * none: both must be refused with the actual availability and leave no
     * session behind.
     */
    private function refuseImpossibleGeneration(FellowshipSmokeContext $context, int $total): void
    {
        $ledger = $context->ledger;
        $fellowship = $context->fellowship;
        $student = $context->student;

        if ($fellowship === null || $student === null) {
            return;
        }

        $id = $fellowship->getKey();
        $sessionsBefore = FellowshipQuizSession::query()->where('user_id', $student->getKey())->count();

        // (a) More than the bank holds.
        $response = $context->api->post('/api/student/fellowships/'.$id.'/sessions', [
            'title' => FellowshipSmokeFixtures::MARKER.'Too many',
            'question_count' => $total + 25,
        ]);

        $ledger->same('Asking for more questions than exist is refused (422)', 422, $response->status, $response);
        $ledger->same('The refusal says why', 'FELLOWSHIP_INSUFFICIENT_QUESTIONS', $response->json('code'), $response);
        $ledger->same(
            'The refusal reports the real availability',
            $total,
            $response->json('errors.available_questions'),
            $response,
        );

        // (b) A combination nothing matches: a difficulty paired with a topic it
        //     never occurs with would be data-dependent, so an impossible
        //     intersection is built from two values of the same filter instead.
        $empty = $context->api->post('/api/student/fellowships/'.$id.'/sessions', [
            'title' => FellowshipSmokeFixtures::MARKER.'Empty pool',
            'question_count' => 1,
            'question_status' => FellowshipQuestionStatusFilter::Answered->value,
        ]);

        $ledger->same('An empty matching pool is refused (422)', 422, $empty->status, $empty);
        $ledger->same('An empty pool reports zero available', 0, $empty->json('errors.available_questions'), $empty);

        $ledger->same(
            'A refused generation creates no session at all',
            $sessionsBefore,
            FellowshipQuizSession::query()->where('user_id', $student->getKey())->count(),
        );

        // (c) A filter value from another Fellowship must be rejected by
        //     validation rather than silently matching nothing.
        $foreignValueId = DB::table('fellowship_filter_values as v')
            ->join('fellowship_filters as f', 'f.id', '=', 'v.fellowship_filter_id')
            ->where('f.fellowship_id', '<>', $id)
            ->value('v.id');

        if ($foreignValueId !== null) {
            $foreignFilterId = (int) DB::table('fellowship_filter_values')->where('id', $foreignValueId)->value('fellowship_filter_id');

            $rejected = $context->api->post('/api/student/fellowships/'.$id.'/sessions', [
                'title' => FellowshipSmokeFixtures::MARKER.'Foreign filter',
                'question_count' => 1,
                'filters' => [$foreignFilterId => [(int) $foreignValueId]],
            ]);

            $ledger->same(
                'A filter value from another Fellowship is rejected (422)',
                422,
                $rejected->status,
                $rejected,
            );
        } else {
            $ledger->skip('A filter value from another Fellowship is rejected', 'No foreign filter value exists to try.');
        }
    }

    /**
     * The pool count, computed here from the rules rather than from the service
     * under test.
     *
     * @param  array<string, mixed>  $criteria
     */
    private function expectedPoolCount(FellowshipSmokeContext $context, array $criteria): int
    {
        $fellowship = $context->fellowship;
        $student = $context->student;

        if ($fellowship === null) {
            return -1;
        }

        $id = $fellowship->getKey();
        $performanceFilterId = $fellowship->performance_filter_id;

        $query = DB::table('fellowship_questions as q')
            ->where('q.fellowship_id', $id)
            ->whereNull('q.deleted_at')
            // Answerable only: two or more options, exactly one of them correct.
            ->whereRaw('(select count(*) from fellowship_question_options o where o.fellowship_question_id = q.id) >= 2')
            ->whereRaw('(select count(*) from fellowship_question_options o where o.fellowship_question_id = q.id and o.is_correct = 1) >= 1');

        $difficulties = $criteria['difficulties'] ?? [];

        if (is_array($difficulties) && $difficulties !== []) {
            $query->whereIn('q.difficulty', $difficulties);
        }

        foreach ($criteria['filters'] ?? [] as $filterId => $valueIds) {
            $valueIds = array_values(array_filter((array) $valueIds));

            if ($valueIds === []) {
                continue;
            }

            if ($performanceFilterId !== null && (int) $filterId === (int) $performanceFilterId) {
                $query->whereIn('q.performance_filter_value_id', $valueIds);

                continue;
            }

            $query->whereExists(function ($sub) use ($filterId, $valueIds): void {
                $sub->from('fellowship_question_filter_values as p')
                    ->whereColumn('p.fellowship_question_id', 'q.id')
                    ->where('p.fellowship_filter_id', (int) $filterId)
                    ->whereIn('p.fellowship_filter_value_id', $valueIds);
            });
        }

        $status = $criteria['question_status'] ?? FellowshipQuestionStatusFilter::All->value;

        if ($status !== FellowshipQuestionStatusFilter::All->value && $student !== null) {
            $answered = DB::table('fellowship_session_questions as sq')
                ->join('fellowship_quiz_sessions as s', 's.id', '=', 'sq.fellowship_quiz_session_id')
                ->whereNotNull('sq.answered_at')
                ->whereNotNull('sq.fellowship_question_id')
                ->where('s.user_id', $student->getKey())
                ->where('s.fellowship_id', $id)
                ->pluck('sq.fellowship_question_id')
                ->map(static fn ($value): int => (int) $value)
                ->unique()
                ->values()
                ->all();

            $status === FellowshipQuestionStatusFilter::Answered->value
                ? $query->whereIn('q.id', $answered === [] ? [0] : $answered)
                : $query->whereNotIn('q.id', $answered === [] ? [0] : $answered);
        }

        return (int) $query->count();
    }

    private function answerableQuestionCount(int $fellowshipId): int
    {
        return (int) DB::table('fellowship_questions as q')
            ->where('q.fellowship_id', $fellowshipId)
            ->whereNull('q.deleted_at')
            ->whereRaw('(select count(*) from fellowship_question_options o where o.fellowship_question_id = q.id) >= 2')
            ->whereRaw('(select count(*) from fellowship_question_options o where o.fellowship_question_id = q.id and o.is_correct = 1) >= 1')
            ->count();
    }

    // ── A whole session ───────────────────────────────────────────────────────

    /**
     * Generate a session, answer every question, and check it completes with the
     * score the answers earn.
     */
    private function runFullSession(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $ledger->group('Student · Session lifecycle');

        if (! $context->asStudent() || $context->fellowship === null) {
            $ledger->skip('Full session run', 'No authenticated student or Fellowship.');

            return;
        }

        $id = $context->fellowship->getKey();
        $title = FellowshipSmokeFixtures::MARKER.'Full run';

        $created = $context->api->post('/api/student/fellowships/'.$id.'/sessions', [
            'title' => $title,
            'question_count' => 6,
            'timer_enabled' => false,
        ]);

        if (! $ledger->same('POST /fellowships/{id}/sessions returns 201', 201, $created->status, $created)) {
            return;
        }

        $sessionId = (int) $created->json('data.id');
        $context->sessions['full'] = $sessionId;
        $ledger->note('Full-run session ID', $sessionId);

        $ledger->same('The session keeps the title the student gave it', $title, $created->json('data.title'), $created);
        $ledger->same('A new session is in progress', 'in_progress', $created->json('data.status'), $created);
        $ledger->same('A new session holds the requested number of questions', 6, $created->json('data.question_count'), $created);
        $ledger->same('A new session starts on the first question', 1, $created->json('data.current_position'), $created);
        $ledger->same('An untimed session carries no time limit', null, $created->json('data.question_time_limit_seconds'), $created);
        $ledger->same('An untimed session reports the timer off', false, $created->json('data.timer_enabled'), $created);

        $questions = $created->json('data.questions', []);

        $ledger->same('The session serves all six questions', 6, count($questions), $created);

        $positions = array_column($questions, 'position');
        $ledger->same('The generated order is 1..6', range(1, 6), $positions, $created);

        $questionIds = array_column($questions, 'fellowship_question_id');
        $ledger->same(
            'No question appears twice in one session',
            count($questionIds),
            count(array_unique($questionIds)),
            $created,
        );

        // An unanswered question must not reveal its answer.
        $first = $questions[0] ?? [];

        $ledger->same('An unanswered question hides whether it is correct', null, $first['is_correct'], $created);
        $ledger->same('An unanswered question hides the correct option', null, $first['correct_option_key'], $created);
        $ledger->same('An unanswered question hides the explanation', null, $first['explanation'], $created);
        $ledger->check(
            'An unanswered question still offers its options',
            is_array($first['options'] ?? null) && count($first['options']) >= 2,
            'options were: '.json_encode($first['options'] ?? null),
            $created,
        );

        $ledger->check(
            'Options carry no correctness flag at all',
            $first['options'] !== [] && ! array_key_exists('is_correct', $first['options'][0] ?? []),
            'An option leaked a correctness flag: '.json_encode($first['options'][0] ?? null),
            $created,
        );

        // Answer every question, deliberately getting some wrong.
        $correctGiven = 0;

        foreach ($questions as $index => $question) {
            $sessionQuestionId = (int) $question['id'];
            $snapshotCorrect = $this->correctKeyFor($sessionQuestionId);
            $options = array_column($question['options'] ?? [], 'key');

            // Every third answer is deliberately wrong.
            $answerWrong = $index % 3 === 2 && count($options) > 1;

            $chosen = $answerWrong
                ? (string) collect($options)->first(fn (string $key): bool => $key !== $snapshotCorrect)
                : (string) $snapshotCorrect;

            if (! $answerWrong) {
                $correctGiven++;
            }

            $response = $context->api->post(
                '/api/student/fellowship-sessions/'.$sessionId.'/questions/'.$sessionQuestionId.'/answer',
                ['option_key' => $chosen],
            );

            if (! $ledger->same('Answer '.($index + 1).' accepted (200)', 200, $response->status, $response)) {
                continue;
            }

            $answered = $response->json('data.question', []);

            $ledger->same('Answer '.($index + 1).' records the chosen option', $chosen, $answered['selected_option_key'] ?? null, $response);
            $ledger->same('Answer '.($index + 1).' is graded from the snapshot', ! $answerWrong, $answered['is_correct'] ?? null, $response);
            $ledger->same('Answer '.($index + 1).' reveals the correct option once answered', $snapshotCorrect, $answered['correct_option_key'] ?? null, $response);
            $ledger->check('Answer '.($index + 1).' is marked answered', ($answered['is_answered'] ?? false) === true, 'is_answered was false.', $response);
            $ledger->same(
                'Answer '.($index + 1).' on an untimed session has no timing verdict',
                null,
                $answered['answered_within_time'],
                $response,
            );

            // Re-submitting must not change the recorded answer.
            $again = $context->api->post(
                '/api/student/fellowship-sessions/'.$sessionId.'/questions/'.$sessionQuestionId.'/answer',
                ['option_key' => (string) collect($options)->last()],
            );

            $ledger->same('Answer '.($index + 1).' cannot be submitted twice (422)', 422, $again->status, $again);
            $ledger->check(
                'The second submission of answer '.($index + 1).' says why',
                in_array($again->json('code'), [
                    'FELLOWSHIP_QUESTION_ALREADY_ANSWERED',
                    // Answering the last question completes the session, so a
                    // resubmission against it is refused for that reason first.
                    'FELLOWSHIP_SESSION_ALREADY_COMPLETED',
                ], true),
                'Unexpected refusal code: '.var_export($again->json('code'), true),
                $again,
            );

            $stored = DB::table('fellowship_session_questions')->where('id', $sessionQuestionId)->first();

            $ledger->same(
                'The second submission left the stored answer alone',
                $chosen,
                $stored->selected_option_key ?? null,
            );
        }

        // The session must now be complete.
        $final = $context->api->get('/api/student/fellowship-sessions/'.$sessionId);

        $ledger->same('A fully answered session is completed', 'completed', $final->json('data.status'), $final);
        $ledger->check('A completed session records when', $final->json('data.completed_at') !== null, 'completed_at is null.', $final);
        $ledger->same('A completed session counts its answers', 6, $final->json('data.answered_questions'), $final);
        $ledger->same('A completed session counts the correct ones', $correctGiven, $final->json('data.correct_answers'), $final);
        $ledger->same('A completed session counts the incorrect ones', 6 - $correctGiven, $final->json('data.incorrect_answers'), $final);
        $ledger->sameNumber(
            'A completed session scores correct over answered',
            round($correctGiven / 6 * 100, 2),
            $final->json('data.average_score'),
            $final,
        );

        // Answered questions are read-only, and the result screen is reachable.
        $result = $context->api->get('/api/student/fellowship-sessions/'.$sessionId.'/result');

        $ledger->same('GET /fellowship-sessions/{id}/result returns 200', 200, $result->status, $result);
        $ledger->sameNumber(
            'The result screen agrees with the session on the score',
            $final->json('data.average_score'),
            $result->json('data.average_score'),
            $result,
        );
        $ledger->check(
            'The result screen breaks the session down by topic',
            is_array($result->json('data.topic_performance')),
            'topic_performance was: '.json_encode($result->json('data.topic_performance')),
            $result,
        );

        // A completed session can be read but not continued.
        $pause = $context->api->post('/api/student/fellowship-sessions/'.$sessionId.'/pause');
        $resume = $context->api->post('/api/student/fellowship-sessions/'.$sessionId.'/resume');

        $ledger->same('A completed session cannot be paused (422)', 422, $pause->status, $pause);
        $ledger->same('Pausing a completed session says why', 'FELLOWSHIP_SESSION_ALREADY_COMPLETED', $pause->json('code'), $pause);
        $ledger->same('A completed session cannot be resumed (422)', 422, $resume->status, $resume);
        $ledger->same('Resuming a completed session says why', 'FELLOWSHIP_SESSION_ALREADY_COMPLETED', $resume->json('code'), $resume);

        // There is no Finish Early: no route offers it.
        $routes = collect(app('router')->getRoutes())->map(fn ($route): string => (string) $route->uri());

        $ledger->check(
            'No endpoint offers to finish a session early',
            $routes->filter(fn (string $uri): bool => str_contains($uri, 'fellowship')
                && (str_contains($uri, 'finish') || str_contains($uri, 'complete') || str_contains($uri, 'abandon')))->isEmpty(),
            'A finish-early route exists: '.$routes->filter(fn (string $uri): bool => str_contains($uri, 'finish'))->implode(', '),
        );
    }

    /**
     * The correct option key, read from the session question's own stored
     * snapshot — which is where grading reads it from.
     */
    private function correctKeyFor(int $sessionQuestionId): ?string
    {
        $row = DB::table('fellowship_session_questions')->where('id', $sessionQuestionId)->first();

        if ($row === null) {
            return null;
        }

        $snapshot = json_decode((string) $row->question_snapshot, associative: true);

        foreach ($snapshot['options'] ?? [] as $option) {
            if (($option['is_correct'] ?? false) === true) {
                return (string) $option['key'];
            }
        }

        return null;
    }

    // ── Sequential navigation ─────────────────────────────────────────────────

    /**
     * The student answers the question they are on. The next one is not
     * answerable until the current one is, and there is no skipping.
     */
    private function enforceSequentialAnswering(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $ledger->group('Student · Sequential navigation');

        if (! $context->asStudent() || $context->fellowship === null) {
            $ledger->skip('Sequential navigation', 'No authenticated student or Fellowship.');

            return;
        }

        $id = $context->fellowship->getKey();

        $created = $context->api->post('/api/student/fellowships/'.$id.'/sessions', [
            'title' => FellowshipSmokeFixtures::MARKER.'Sequential',
            'question_count' => 4,
        ]);

        if (! $ledger->same('Sequential session generated (201)', 201, $created->status, $created)) {
            return;
        }

        $sessionId = (int) $created->json('data.id');
        $context->sessions['sequential'] = $sessionId;

        $questions = $created->json('data.questions', []);

        if (count($questions) < 3) {
            $ledger->skip('Sequential navigation', 'The session held too few questions.');

            return;
        }

        $second = $questions[1];
        $third = $questions[2];

        // The second question cannot be answered while the first is unanswered.
        $outOfOrder = $context->api->post(
            '/api/student/fellowship-sessions/'.$sessionId.'/questions/'.$second['id'].'/answer',
            ['option_key' => (string) ($second['options'][0]['key'] ?? '')],
        );

        $ledger->same('The next question cannot be answered first (422)', 422, $outOfOrder->status, $outOfOrder);
        $ledger->same('Answering out of order says why', 'FELLOWSHIP_QUESTION_OUT_OF_ORDER', $outOfOrder->json('code'), $outOfOrder);

        $ledger->same(
            'A refused out-of-order answer records nothing',
            null,
            DB::table('fellowship_session_questions')->where('id', $second['id'])->value('answered_at'),
        );

        // Nor can the third be skipped to.
        $skipAhead = $context->api->post(
            '/api/student/fellowship-sessions/'.$sessionId.'/questions/'.$third['id'].'/answer',
            ['option_key' => (string) ($third['options'][0]['key'] ?? '')],
        );

        $ledger->same('A later question cannot be skipped to (422)', 422, $skipAhead->status, $skipAhead);

        // An option that is not one of this question's own must be refused.
        $firstQuestion = $questions[0];

        $foreignOption = $context->api->post(
            '/api/student/fellowship-sessions/'.$sessionId.'/questions/'.$firstQuestion['id'].'/answer',
            ['option_key' => 'this-key-belongs-to-no-option'],
        );

        $ledger->same('An option outside the question is refused (422)', 422, $foreignOption->status, $foreignOption);
        $ledger->same('A foreign option says why', 'FELLOWSHIP_OPTION_NOT_IN_QUESTION', $foreignOption->json('code'), $foreignOption);

        // A missing option key is a validation error, not a 500.
        $missingKey = $context->api->post(
            '/api/student/fellowship-sessions/'.$sessionId.'/questions/'.$firstQuestion['id'].'/answer',
            [],
        );

        $ledger->same('A missing option key is a validation error (422)', 422, $missingKey->status, $missingKey);

        // Answer the first, and the position must move to the second.
        $answered = $context->api->post(
            '/api/student/fellowship-sessions/'.$sessionId.'/questions/'.$firstQuestion['id'].'/answer',
            ['option_key' => (string) $this->correctKeyFor((int) $firstQuestion['id'])],
        );

        $ledger->same('The first question can be answered (200)', 200, $answered->status, $answered);
        $ledger->same('Answering advances to the next question', 2, $answered->json('data.session.current_position'), $answered);

        // Now the second is answerable.
        $nowAllowed = $context->api->post(
            '/api/student/fellowship-sessions/'.$sessionId.'/questions/'.$second['id'].'/answer',
            ['option_key' => (string) $this->correctKeyFor((int) $second['id'])],
        );

        $ledger->same('The next question becomes answerable once the current one is answered', 200, $nowAllowed->status, $nowAllowed);

        // A question from another session must be refused.
        $otherSessionId = $context->sessions['full'] ?? null;

        if ($otherSessionId !== null) {
            $foreignQuestionId = DB::table('fellowship_session_questions')
                ->where('fellowship_quiz_session_id', $otherSessionId)
                ->value('id');

            if ($foreignQuestionId !== null) {
                $mismatched = $context->api->post(
                    '/api/student/fellowship-sessions/'.$sessionId.'/questions/'.$foreignQuestionId.'/answer',
                    ['option_key' => 'x'],
                );

                $ledger->same('A question from another session is refused (404)', 404, $mismatched->status, $mismatched);
                $ledger->same('A mismatched question says why', 'FELLOWSHIP_QUESTION_NOT_IN_SESSION', $mismatched->json('code'), $mismatched);
            }
        }

        // An unfinished session stays in progress rather than being abandoned.
        $ledger->same(
            'A session left part way through stays in progress',
            FellowshipSessionStatus::InProgress,
            FellowshipQuizSession::query()->find($sessionId)?->status,
        );
    }

    // ── Ownership ─────────────────────────────────────────────────────────────

    /**
     * One student must not be able to read or touch another's session — and the
     * refusal must be "not found" rather than "forbidden", which would confirm
     * the session exists.
     */
    private function enforceOwnership(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $ledger->group('Student · Ownership');

        $sessionId = $context->sessions['sequential'] ?? $context->sessions['full'] ?? null;

        if ($sessionId === null) {
            $ledger->skip('Ownership enforced', 'No session exists to try reaching.');

            return;
        }

        if (! $context->asOtherStudent()) {
            $ledger->skip('Ownership enforced', 'The second student has no token.');

            return;
        }

        $questionId = DB::table('fellowship_session_questions')
            ->where('fellowship_quiz_session_id', $sessionId)
            ->whereNull('answered_at')
            ->value('id');

        $attempts = [
            'read the session' => fn (): SmokeApiResponse => $context->api->get('/api/student/fellowship-sessions/'.$sessionId),
            'read the result' => fn (): SmokeApiResponse => $context->api->get('/api/student/fellowship-sessions/'.$sessionId.'/result'),
            'pause the session' => fn (): SmokeApiResponse => $context->api->post('/api/student/fellowship-sessions/'.$sessionId.'/pause'),
            'resume the session' => fn (): SmokeApiResponse => $context->api->post('/api/student/fellowship-sessions/'.$sessionId.'/resume'),
        ];

        if ($questionId !== null) {
            $attempts['answer a question'] = fn (): SmokeApiResponse => $context->api->post(
                '/api/student/fellowship-sessions/'.$sessionId.'/questions/'.$questionId.'/answer',
                ['option_key' => 'x'],
            );
        }

        foreach ($attempts as $label => $call) {
            $response = $call();

            $ledger->same('Another student cannot '.$label.' (404)', 404, $response->status, $response);
        }

        // And nothing of the session changed.
        $ledger->same(
            'A rejected cross-student request changed nothing',
            FellowshipSessionStatus::InProgress->value,
            (string) DB::table('fellowship_quiz_sessions')->where('id', $sessionId)->value('status'),
        );

        if ($questionId !== null) {
            $ledger->same(
                'A rejected cross-student answer recorded nothing',
                null,
                DB::table('fellowship_session_questions')->where('id', $questionId)->value('answered_at'),
            );
        }

        // The other student's own session history must not show it.
        $history = $context->api->get('/api/student/fellowships/'.$context->fellowship?->getKey().'/sessions');

        $ledger->same('Another student sees none of these sessions', 0, count($history->data()), $history);

        $context->asStudent();
    }

    // ── Session history ───────────────────────────────────────────────────────

    private function readSessionHistory(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $ledger->group('Student · Session history');

        if (! $context->asStudent() || $context->fellowship === null) {
            $ledger->skip('Session history read', 'No authenticated student or Fellowship.');

            return;
        }

        $id = $context->fellowship->getKey();
        $response = $context->api->get('/api/student/fellowships/'.$id.'/sessions');

        if (! $ledger->same('GET /fellowships/{id}/sessions returns 200', 200, $response->status, $response)) {
            return;
        }

        $owned = FellowshipQuizSession::query()
            ->where('user_id', $context->student?->getKey())
            ->where('fellowship_id', $id)
            ->count();

        $ledger->check(
            'Session history is paginated',
            $response->json('meta.total') !== null || $response->json('pagination.total') !== null,
            'No pagination metadata in the envelope: '.mb_substr($response->raw, 0, 300),
            $response,
        );

        $ledger->same(
            'Session history lists every session the student owns',
            $owned,
            (int) ($response->json('meta.total') ?? $response->json('pagination.total') ?? -1),
            $response,
        );

        $rows = $response->data();
        $first = $rows[0] ?? [];

        foreach ([
            'id', 'title', 'status', 'total_questions', 'answered_questions', 'correct_answers',
            'incorrect_answers', 'average_score', 'study_time_seconds', 'performance_topics',
            'current_position', 'can_resume', 'can_view_result', 'started_at', 'completed_at',
        ] as $key) {
            $ledger->check(
                'A session history row carries "'.$key.'"',
                array_key_exists($key, $first),
                'Row keys: '.implode(', ', array_keys($first)),
                $response,
            );
        }

        // Newest first.
        $ids = array_column($rows, 'id');
        $sorted = $ids;
        rsort($sorted);

        $ledger->same('Session history is newest first', $sorted, $ids, $response);

        // A smaller page must still report the full total.
        $paged = $context->api->get('/api/student/fellowships/'.$id.'/sessions?per_page=1');

        $ledger->same('A one-row page returns one row', 1, count($paged->data()), $paged);
        $ledger->same(
            'A one-row page still reports the full total',
            $owned,
            (int) ($paged->json('meta.total') ?? $paged->json('pagination.total') ?? -1),
            $paged,
        );

        $ledger->note('Sessions owned by the smoke student', $owned);
    }

    private function egyptCountryId(): ?int
    {
        $table = (string) config('world.migrations.countries.table_name', 'countries');

        $id = DB::table($table)->where('iso2', 'EG')->value('id');

        return $id === null ? null : (int) $id;
    }
}
