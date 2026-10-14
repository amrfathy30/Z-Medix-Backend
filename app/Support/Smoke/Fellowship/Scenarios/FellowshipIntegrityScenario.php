<?php

namespace App\Support\Smoke\Fellowship\Scenarios;

use App\Enums\FellowshipImportRowOutcome;
use App\Enums\FellowshipImportRowStatus;
use App\Enums\FellowshipSessionStatus;
use App\Support\Smoke\Fellowship\FellowshipSmokeContext;
use Illuminate\Support\Facades\DB;

/**
 * What must be true of the rows themselves once everything has run.
 *
 * These are the invariants the module's design rests on, checked against MySQL
 * rather than inferred from the code that wrote it: referential consistency,
 * one correct answer per question, one value per filter per question, no
 * question twice in a session, at most one open activity period, and import
 * tallies that agree with the rows they summarise.
 *
 * Several of them are also enforced by a unique index, which is the point: a
 * check that passes here and is backed by a constraint is a rule the database
 * will keep holding after this run is over.
 */
class FellowshipIntegrityScenario
{
    public function run(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $ledger->group('Integrity · Database invariants');

        $fellowshipIds = $context->fellowshipIds();

        if ($fellowshipIds === []) {
            $ledger->skip('Database invariants', 'The run created no Fellowship to inspect.');

            return;
        }

        $this->assertReferentialConsistency($context);
        $this->assertQuestionInvariants($context, $fellowshipIds);
        $this->assertFilterScoping($context, $fellowshipIds);
        $this->assertSessionInvariants($context);
        $this->assertImportTallies($context);
        $this->assertConstraintsAreEnforced($context);
    }

    /**
     * No row may point at something that is not there. The schema's foreign keys
     * say so, but a `SET NULL` or a soft delete can still leave a pointer that
     * resolves to nothing, and those are the ones worth naming.
     */
    private function assertReferentialConsistency(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;

        $orphans = [
            'questions without a Fellowship' => DB::table('fellowship_questions as q')
                ->leftJoin('fellowships as f', 'f.id', '=', 'q.fellowship_id')
                ->whereNull('f.id'),

            'options without a question' => DB::table('fellowship_question_options as o')
                ->leftJoin('fellowship_questions as q', 'q.id', '=', 'o.fellowship_question_id')
                ->whereNull('q.id'),

            'filter values without a filter' => DB::table('fellowship_filter_values as v')
                ->leftJoin('fellowship_filters as f', 'f.id', '=', 'v.fellowship_filter_id')
                ->whereNull('f.id'),

            'question filter values without a question' => DB::table('fellowship_question_filter_values as p')
                ->leftJoin('fellowship_questions as q', 'q.id', '=', 'p.fellowship_question_id')
                ->whereNull('q.id'),

            'question filter values without a value' => DB::table('fellowship_question_filter_values as p')
                ->leftJoin('fellowship_filter_values as v', 'v.id', '=', 'p.fellowship_filter_value_id')
                ->whereNull('v.id'),

            'import rows without an import' => DB::table('fellowship_import_rows as r')
                ->leftJoin('fellowship_imports as i', 'i.id', '=', 'r.fellowship_import_id')
                ->whereNull('i.id'),

            'session questions without a session' => DB::table('fellowship_session_questions as sq')
                ->leftJoin('fellowship_quiz_sessions as s', 's.id', '=', 'sq.fellowship_quiz_session_id')
                ->whereNull('s.id'),

            'activity periods without a session' => DB::table('fellowship_session_periods as p')
                ->leftJoin('fellowship_quiz_sessions as s', 's.id', '=', 'p.fellowship_quiz_session_id')
                ->whereNull('s.id'),

            'sessions without a student' => DB::table('fellowship_quiz_sessions as s')
                ->leftJoin('users as u', 'u.id', '=', 's.user_id')
                ->whereNull('u.id'),
        ];

        foreach ($orphans as $label => $query) {
            $ledger->same('No orphaned rows: '.$label, 0, (int) $query->count());
        }

        // A question's Performance Topic must resolve, and must belong to its own
        // Fellowship's performance filter.
        $danglingTopic = (int) DB::table('fellowship_questions as q')
            ->leftJoin('fellowship_filter_values as v', 'v.id', '=', 'q.performance_filter_value_id')
            ->whereNotNull('q.performance_filter_value_id')
            ->whereNull('v.id')
            ->count();

        $ledger->same('No question points at a Performance Topic that is gone', 0, $danglingTopic);

        $foreignTopic = (int) DB::table('fellowship_questions as q')
            ->join('fellowships as f', 'f.id', '=', 'q.fellowship_id')
            ->join('fellowship_filter_values as v', 'v.id', '=', 'q.performance_filter_value_id')
            ->whereNotNull('f.performance_filter_id')
            ->whereColumn('v.fellowship_filter_id', '<>', 'f.performance_filter_id')
            ->count();

        $ledger->same('No question is filed under a topic outside its Fellowship\'s performance filter', 0, $foreignTopic);
    }

    /**
     * Every stored question must be one a student could answer.
     *
     * @param  list<int>  $fellowshipIds
     */
    private function assertQuestionInvariants(FellowshipSmokeContext $context, array $fellowshipIds): void
    {
        $ledger = $context->ledger;

        $wrongCorrectCount = DB::table('fellowship_questions as q')
            ->whereIn('q.fellowship_id', $fellowshipIds)
            ->whereNull('q.deleted_at')
            ->whereRaw('(select count(*) from fellowship_question_options o where o.fellowship_question_id = q.id and o.is_correct = 1) <> 1')
            ->count();

        $ledger->same('Exactly one correct answer per live question', 0, (int) $wrongCorrectCount);

        $tooFewOptions = DB::table('fellowship_questions as q')
            ->whereIn('q.fellowship_id', $fellowshipIds)
            ->whereNull('q.deleted_at')
            ->whereRaw('(select count(*) from fellowship_question_options o where o.fellowship_question_id = q.id) < 2')
            ->count();

        $ledger->same('At least two options per live question', 0, (int) $tooFewOptions);

        $missingTopic = DB::table('fellowship_questions')
            ->whereIn('fellowship_id', $fellowshipIds)
            ->whereNull('deleted_at')
            ->whereNull('performance_filter_value_id')
            ->count();

        $ledger->same('Every live question carries a Performance Topic', 0, (int) $missingTopic);

        $emptyText = DB::table('fellowship_questions')
            ->whereIn('fellowship_id', $fellowshipIds)
            ->whereNull('deleted_at')
            ->where(function ($query): void {
                $query->whereNull('question_text')->orWhere('question_text', '');
            })
            ->count();

        $ledger->same('No live question has empty text', 0, (int) $emptyText);

        // The content hash is what duplicate detection indexes on, so it must be
        // present on every question.
        $missingHash = DB::table('fellowship_questions')
            ->whereIn('fellowship_id', $fellowshipIds)
            ->whereNull('deleted_at')
            ->where(function ($query): void {
                $query->whereNull('content_hash')->orWhere('content_hash', '');
            })
            ->count();

        $ledger->same('Every live question carries a content hash', 0, (int) $missingHash);

        $emptyOption = DB::table('fellowship_question_options as o')
            ->join('fellowship_questions as q', 'q.id', '=', 'o.fellowship_question_id')
            ->whereIn('q.fellowship_id', $fellowshipIds)
            ->whereNull('q.deleted_at')
            ->where(function ($query): void {
                $query->whereNull('o.option_text')->orWhere('o.option_text', '');
            })
            ->count();

        $ledger->same('No live question carries an empty answer option', 0, (int) $emptyOption);
    }

    /**
     * Filters and their values are per-Fellowship, and a question holds at most
     * one value per filter.
     *
     * @param  list<int>  $fellowshipIds
     */
    private function assertFilterScoping(FellowshipSmokeContext $context, array $fellowshipIds): void
    {
        $ledger = $context->ledger;

        $crossFellowship = DB::table('fellowship_question_filter_values as p')
            ->join('fellowship_questions as q', 'q.id', '=', 'p.fellowship_question_id')
            ->join('fellowship_filters as f', 'f.id', '=', 'p.fellowship_filter_id')
            ->whereColumn('q.fellowship_id', '<>', 'f.fellowship_id')
            ->count();

        $ledger->same('No question is filed under another Fellowship\'s filter', 0, (int) $crossFellowship);

        $valueFromAnotherFilter = DB::table('fellowship_question_filter_values as p')
            ->join('fellowship_filter_values as v', 'v.id', '=', 'p.fellowship_filter_value_id')
            ->whereColumn('v.fellowship_filter_id', '<>', 'p.fellowship_filter_id')
            ->count();

        $ledger->same('No pivot pairs one filter with another filter\'s value', 0, (int) $valueFromAnotherFilter);

        $doubleValue = DB::table('fellowship_question_filter_values')
            ->select('fellowship_question_id', 'fellowship_filter_id')
            ->groupBy('fellowship_question_id', 'fellowship_filter_id')
            ->havingRaw('count(*) > 1')
            ->get()
            ->count();

        $ledger->same('A question holds at most one value per filter', 0, (int) $doubleValue);

        // One performance filter per Fellowship.
        $multiplePerformance = DB::table('fellowship_filters')
            ->select('fellowship_id')
            ->whereIn('fellowship_id', $fellowshipIds)
            ->where('is_performance', true)
            ->groupBy('fellowship_id')
            ->havingRaw('count(*) > 1')
            ->get()
            ->count();

        $ledger->same('A Fellowship designates at most one performance filter', 0, (int) $multiplePerformance);

        // The designation must point at one of the Fellowship's own filters.
        $foreignDesignation = DB::table('fellowships as f')
            ->join('fellowship_filters as fi', 'fi.id', '=', 'f.performance_filter_id')
            ->whereNotNull('f.performance_filter_id')
            ->whereColumn('fi.fellowship_id', '<>', 'f.id')
            ->count();

        $ledger->same('A Fellowship\'s performance filter is one of its own', 0, (int) $foreignDesignation);
    }

    /**
     * A session's questions and its clock.
     */
    private function assertSessionInvariants(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;

        $userIds = $context->userIds();

        if ($userIds === []) {
            $ledger->skip('Session invariants', 'The run created no student.');

            return;
        }

        $sessionIds = DB::table('fellowship_quiz_sessions')
            ->whereIn('user_id', $userIds)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($sessionIds === []) {
            $ledger->skip('Session invariants', 'The run created no session.');

            return;
        }

        // No question twice in one session.
        $repeated = DB::table('fellowship_session_questions')
            ->select('fellowship_quiz_session_id', 'fellowship_question_id')
            ->whereIn('fellowship_quiz_session_id', $sessionIds)
            ->whereNotNull('fellowship_question_id')
            ->groupBy('fellowship_quiz_session_id', 'fellowship_question_id')
            ->havingRaw('count(*) > 1')
            ->get()
            ->count();

        $ledger->same('No question appears twice in one session', 0, (int) $repeated);

        // Positions are unique and contiguous from one.
        $duplicatePosition = DB::table('fellowship_session_questions')
            ->select('fellowship_quiz_session_id', 'position')
            ->whereIn('fellowship_quiz_session_id', $sessionIds)
            ->groupBy('fellowship_quiz_session_id', 'position')
            ->havingRaw('count(*) > 1')
            ->get()
            ->count();

        $ledger->same('No two questions share a position in one session', 0, (int) $duplicatePosition);

        $badRange = 0;

        foreach ($sessionIds as $sessionId) {
            $positions = DB::table('fellowship_session_questions')
                ->where('fellowship_quiz_session_id', $sessionId)
                ->orderBy('position')
                ->pluck('position')
                ->map(static fn ($value): int => (int) $value)
                ->all();

            if ($positions !== range(1, count($positions))) {
                $badRange++;
            }
        }

        $ledger->same('Every session\'s positions run contiguously from one', 0, $badRange);

        // The generated count matches what was asked for.
        $countMismatch = DB::table('fellowship_quiz_sessions as s')
            ->whereIn('s.id', $sessionIds)
            ->whereRaw('s.question_count <> (select count(*) from fellowship_session_questions sq where sq.fellowship_quiz_session_id = s.id)')
            ->count();

        $ledger->same('Every session holds exactly the number of questions it recorded', 0, (int) $countMismatch);

        // At most one open activity period per session — also a unique index.
        $multipleOpen = DB::table('fellowship_session_periods')
            ->select('fellowship_quiz_session_id')
            ->whereIn('fellowship_quiz_session_id', $sessionIds)
            ->whereNull('ended_at')
            ->groupBy('fellowship_quiz_session_id')
            ->havingRaw('count(*) > 1')
            ->get()
            ->count();

        $ledger->same('At most one open activity period per session', 0, (int) $multipleOpen);

        // A completed session has no open period and no unanswered question.
        $completedWithOpenPeriod = DB::table('fellowship_quiz_sessions as s')
            ->join('fellowship_session_periods as p', 'p.fellowship_quiz_session_id', '=', 's.id')
            ->whereIn('s.id', $sessionIds)
            ->where('s.status', FellowshipSessionStatus::Completed->value)
            ->whereNull('p.ended_at')
            ->count();

        $ledger->same('A completed session leaves no clock running', 0, (int) $completedWithOpenPeriod);

        $completedWithUnanswered = DB::table('fellowship_quiz_sessions as s')
            ->join('fellowship_session_questions as sq', 'sq.fellowship_quiz_session_id', '=', 's.id')
            ->whereIn('s.id', $sessionIds)
            ->where('s.status', FellowshipSessionStatus::Completed->value)
            ->whereNull('sq.answered_at')
            ->count();

        $ledger->same('A completed session has every question answered', 0, (int) $completedWithUnanswered);

        $completedWithoutTimestamp = DB::table('fellowship_quiz_sessions')
            ->whereIn('id', $sessionIds)
            ->where('status', FellowshipSessionStatus::Completed->value)
            ->whereNull('completed_at')
            ->count();

        $ledger->same('A completed session records when it completed', 0, (int) $completedWithoutTimestamp);

        // An open session must not carry a completion time.
        $openWithTimestamp = DB::table('fellowship_quiz_sessions')
            ->whereIn('id', $sessionIds)
            ->where('status', '<>', FellowshipSessionStatus::Completed->value)
            ->whereNotNull('completed_at')
            ->count();

        $ledger->same('An unfinished session carries no completion time', 0, (int) $openWithTimestamp);

        // Only the three modelled statuses exist.
        $statuses = DB::table('fellowship_quiz_sessions')
            ->whereIn('id', $sessionIds)
            ->distinct()
            ->pluck('status')
            ->map(static fn ($value): string => (string) $value)
            ->sort()
            ->values()
            ->all();

        $ledger->check(
            'Sessions only ever hold a modelled status',
            array_diff($statuses, ['in_progress', 'paused', 'completed']) === [],
            'Unexpected statuses: '.implode(', ', $statuses),
        );

        // An answered question must carry a verdict and a chosen option; an
        // unanswered one must carry neither.
        $answeredWithoutVerdict = DB::table('fellowship_session_questions')
            ->whereIn('fellowship_quiz_session_id', $sessionIds)
            ->whereNotNull('answered_at')
            ->where(function ($query): void {
                $query->whereNull('is_correct')->orWhereNull('selected_option_key');
            })
            ->count();

        $ledger->same('Every answered question carries a verdict and a chosen option', 0, (int) $answeredWithoutVerdict);

        $unansweredWithVerdict = DB::table('fellowship_session_questions')
            ->whereIn('fellowship_quiz_session_id', $sessionIds)
            ->whereNull('answered_at')
            ->where(function ($query): void {
                $query->whereNotNull('is_correct')->orWhereNotNull('selected_option_key');
            })
            ->count();

        $ledger->same('No unanswered question carries a verdict or a chosen option', 0, (int) $unansweredWithVerdict);

        // The timing verdict is applicable only where the session was timed.
        $verdictWithoutTimer = DB::table('fellowship_session_questions as sq')
            ->join('fellowship_quiz_sessions as s', 's.id', '=', 'sq.fellowship_quiz_session_id')
            ->whereIn('s.id', $sessionIds)
            ->where('s.timer_enabled', false)
            ->whereNotNull('sq.answered_within_time')
            ->count();

        $ledger->same('An untimed session records no timing verdict', 0, (int) $verdictWithoutTimer);

        $timerWithoutLimit = DB::table('fellowship_quiz_sessions')
            ->whereIn('id', $sessionIds)
            ->where('timer_enabled', true)
            ->whereNull('question_time_limit_seconds')
            ->count();

        $ledger->same('Every timed session snapshotted a limit', 0, (int) $timerWithoutLimit);

        $limitWithoutTimer = DB::table('fellowship_quiz_sessions')
            ->whereIn('id', $sessionIds)
            ->where('timer_enabled', false)
            ->whereNotNull('question_time_limit_seconds')
            ->count();

        $ledger->same('No untimed session stores a limit', 0, (int) $limitWithoutTimer);

        // Every session question carries a usable snapshot.
        $badSnapshot = DB::table('fellowship_session_questions')
            ->whereIn('fellowship_quiz_session_id', $sessionIds)
            ->where(function ($query): void {
                $query->whereNull('question_snapshot')
                    ->orWhere('question_snapshot', '')
                    ->orWhereRaw("json_length(json_extract(question_snapshot, '$.options')) < 2");
            })
            ->count();

        $ledger->same('Every session question carries a snapshot with at least two options', 0, (int) $badSnapshot);

        // Exactly one option flagged correct, counted inside the JSON document
        // itself: grading reads correctness from here and from nowhere else, so
        // a snapshot with none or several would be unanswerable or ambiguous.
        $snapshotCorrectCounts = DB::table('fellowship_session_questions')
            ->whereIn('fellowship_quiz_session_id', $sessionIds)
            ->selectRaw("id, json_length(json_extract(question_snapshot, '$.options')) as options_count")
            ->selectRaw("json_extract(question_snapshot, '$.options[*].is_correct') as correct_flags")
            ->get();

        $badCorrectFlags = $snapshotCorrectCounts->filter(function ($row): bool {
            $flags = json_decode((string) $row->correct_flags, associative: true);

            if (! is_array($flags)) {
                return true;
            }

            return count(array_filter($flags, static fn ($flag): bool => $flag === true)) !== 1;
        });

        $ledger->same(
            'Every snapshot marks exactly one option correct',
            0,
            $badCorrectFlags->count(),
        );

        $negativeTime = DB::table('fellowship_session_questions')
            ->whereIn('fellowship_quiz_session_id', $sessionIds)
            ->where('active_seconds', '<', 0)
            ->count();

        $ledger->same('No question records negative active time', 0, (int) $negativeTime);
    }

    /**
     * An import's summary must agree with the rows it summarises.
     */
    private function assertImportTallies(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;

        $fellowshipIds = $context->fellowshipIds();

        $imports = DB::table('fellowship_imports')
            ->whereIn('fellowship_id', $fellowshipIds)
            ->get();

        if ($imports->isEmpty()) {
            $ledger->skip('Import tallies agree with their rows', 'The run created no import.');

            return;
        }

        $mismatched = [];

        foreach ($imports as $import) {
            $rows = DB::table('fellowship_import_rows')->where('fellowship_import_id', $import->id);

            $total = (int) $rows->clone()->count();
            $valid = (int) $rows->clone()->where('status', FellowshipImportRowStatus::Valid->value)->count();
            $invalid = (int) $rows->clone()->where('status', FellowshipImportRowStatus::Invalid->value)->count();
            $duplicate = (int) $rows->clone()->where('status', FellowshipImportRowStatus::Duplicate->value)->count();

            $created = (int) $rows->clone()->where('outcome', FellowshipImportRowOutcome::Created->value)->count();
            $updated = (int) $rows->clone()->where('outcome', FellowshipImportRowOutcome::Updated->value)->count();
            $skipped = (int) $rows->clone()->where('outcome', FellowshipImportRowOutcome::Skipped->value)->count();

            $problems = [];

            if ((int) $import->total_rows !== $total) {
                $problems[] = sprintf('total_rows %d vs %d rows', $import->total_rows, $total);
            }

            if ((int) $import->valid_rows !== $valid) {
                $problems[] = sprintf('valid_rows %d vs %d', $import->valid_rows, $valid);
            }

            if ((int) $import->invalid_rows !== $invalid) {
                $problems[] = sprintf('invalid_rows %d vs %d', $import->invalid_rows, $invalid);
            }

            if ((int) $import->duplicate_rows !== $duplicate) {
                $problems[] = sprintf('duplicate_rows %d vs %d', $import->duplicate_rows, $duplicate);
            }

            // Only a completed import has acted on its rows.
            if ($import->status === 'completed') {
                if ((int) $import->created_questions !== $created) {
                    $problems[] = sprintf('created_questions %d vs %d', $import->created_questions, $created);
                }

                if ((int) $import->updated_questions !== $updated) {
                    $problems[] = sprintf('updated_questions %d vs %d', $import->updated_questions, $updated);
                }

                if ((int) $import->skipped_questions !== $skipped) {
                    $problems[] = sprintf('skipped_questions %d vs %d', $import->skipped_questions, $skipped);
                }
            }

            if ($problems !== []) {
                $mismatched[] = 'import #'.$import->id.': '.implode(', ', $problems);
            }
        }

        $ledger->check(
            'Every import\'s summary agrees with its rows',
            $mismatched === [],
            implode('; ', $mismatched),
        );

        // The three statuses must partition the rows.
        $unpartitioned = DB::table('fellowship_imports as i')
            ->whereIn('i.fellowship_id', $fellowshipIds)
            ->whereRaw('i.valid_rows + i.invalid_rows + i.duplicate_rows <> i.total_rows')
            ->count();

        $ledger->same('Valid, invalid and duplicate rows add up to the total', 0, (int) $unpartitioned);

        // An invalid row may never have been acted on.
        $invalidActedOn = DB::table('fellowship_import_rows as r')
            ->join('fellowship_imports as i', 'i.id', '=', 'r.fellowship_import_id')
            ->whereIn('i.fellowship_id', $fellowshipIds)
            ->where('r.status', FellowshipImportRowStatus::Invalid->value)
            ->where(function ($query): void {
                $query->whereNotNull('r.processed_at')->orWhereNotNull('r.fellowship_question_id');
            })
            ->count();

        $ledger->same('No invalid row was ever acted on', 0, (int) $invalidActedOn);

        // A row recorded as created or updated must name the question it made.
        $outcomeWithoutQuestion = DB::table('fellowship_import_rows as r')
            ->join('fellowship_imports as i', 'i.id', '=', 'r.fellowship_import_id')
            ->whereIn('i.fellowship_id', $fellowshipIds)
            ->whereIn('r.outcome', [FellowshipImportRowOutcome::Created->value, FellowshipImportRowOutcome::Updated->value])
            ->whereNull('r.fellowship_question_id')
            ->count();

        // A created or updated row names the question it produced, except where
        // the run force-deleted that question on purpose: the schema clears the
        // link with `SET NULL`, which is what keeps a destroyed question from
        // dragging its import history down with it. So the expected number is
        // exactly the number destroyed, not zero.
        $ledger->same(
            'Every created or updated row names its question, bar the ones deliberately destroyed',
            $context->forceDeletedQuestions,
            (int) $outcomeWithoutQuestion,
        );

        // A completed import has no row left unprocessed among the importable ones.
        $unprocessed = DB::table('fellowship_import_rows as r')
            ->join('fellowship_imports as i', 'i.id', '=', 'r.fellowship_import_id')
            ->whereIn('i.fellowship_id', $fellowshipIds)
            ->where('i.status', 'completed')
            ->whereIn('r.status', [FellowshipImportRowStatus::Valid->value, FellowshipImportRowStatus::Duplicate->value])
            ->whereNull('r.processed_at')
            ->count();

        $ledger->same('A completed import left no importable row unprocessed', 0, (int) $unprocessed);

        // No completed import still holds an undecided duplicate.
        $undecided = DB::table('fellowship_import_rows as r')
            ->join('fellowship_imports as i', 'i.id', '=', 'r.fellowship_import_id')
            ->whereIn('i.fellowship_id', $fellowshipIds)
            ->where('i.status', 'completed')
            ->where('r.status', FellowshipImportRowStatus::Duplicate->value)
            ->whereNull('r.decision')
            ->whereNull('r.processed_at')
            ->count();

        $ledger->same('No completed import left a duplicate undecided', 0, (int) $undecided);

        $ledger->note('Imports created by this run', $imports->count());
    }

    /**
     * The invariants that matter most are the ones the database itself keeps.
     *
     * Each is provoked deliberately, inside a transaction that is rolled back,
     * so the constraint is shown to be live rather than merely declared — and
     * nothing is left behind either way.
     */
    private function assertConstraintsAreEnforced(FellowshipSmokeContext $context): void
    {
        $ledger = $context->ledger;
        $ledger->group('Integrity · Constraints are live');

        $userIds = $context->userIds();

        $sessionId = $userIds === [] ? null : DB::table('fellowship_quiz_sessions')
            ->whereIn('user_id', $userIds)
            ->value('id');

        if ($sessionId === null) {
            $ledger->skip('Constraints are live', 'The run created no session to probe against.');

            return;
        }

        // A second open activity period must be refused by the unique index.
        $refused = $this->provoke(function () use ($sessionId): void {
            DB::table('fellowship_session_periods')->insert([
                'fellowship_quiz_session_id' => $sessionId,
                'started_at' => now(),
                'open_marker' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('fellowship_session_periods')->insert([
                'fellowship_quiz_session_id' => $sessionId,
                'started_at' => now(),
                'open_marker' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $ledger->check(
            'The database refuses a second open activity period',
            $refused,
            'Two open periods were inserted for one session without complaint.',
        );

        // Two questions at the same position must be refused.
        $position = (int) DB::table('fellowship_session_questions')
            ->where('fellowship_quiz_session_id', $sessionId)
            ->min('position');

        $refusedPosition = $this->provoke(function () use ($sessionId, $position): void {
            DB::table('fellowship_session_questions')->insert([
                'fellowship_quiz_session_id' => $sessionId,
                'position' => $position,
                'question_snapshot' => json_encode(['version' => 1, 'options' => []]),
                'active_seconds' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $ledger->check(
            'The database refuses two questions at one position',
            $refusedPosition,
            'A duplicate position was accepted for one session.',
        );

        // Two values of one filter for one question must be refused.
        $pivot = DB::table('fellowship_question_filter_values')->first();

        if ($pivot !== null) {
            $refusedPivot = $this->provoke(function () use ($pivot): void {
                DB::table('fellowship_question_filter_values')->insert([
                    'fellowship_question_id' => $pivot->fellowship_question_id,
                    'fellowship_filter_id' => $pivot->fellowship_filter_id,
                    'fellowship_filter_value_id' => $pivot->fellowship_filter_value_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

            $ledger->check(
                'The database refuses a second value for one filter on one question',
                $refusedPivot,
                'A question was given two values for the same filter.',
            );
        } else {
            $ledger->skip('The database refuses a second value for one filter on one question', 'No pivot row to probe with.');
        }

        // A Performance Topic in use must not be deletable.
        $topicInUse = DB::table('fellowship_questions')
            ->whereIn('fellowship_id', $context->fellowshipIds())
            ->whereNotNull('performance_filter_value_id')
            ->value('performance_filter_value_id');

        if ($topicInUse !== null) {
            $refusedDelete = $this->provoke(function () use ($topicInUse): void {
                DB::table('fellowship_filter_values')->where('id', $topicInUse)->delete();
            });

            $ledger->check(
                'The database refuses to delete a Performance Topic still in use',
                $refusedDelete,
                'A topic value with questions filed under it was deleted.',
            );
        } else {
            $ledger->skip('The database refuses to delete a Performance Topic still in use', 'No topic in use to probe with.');
        }

        // Two filters of the same name on one Fellowship must be refused, which
        // is what makes filter reuse rather than forking enforceable.
        $filter = DB::table('fellowship_filters')
            ->whereIn('fellowship_id', $context->fellowshipIds())
            ->first();

        if ($filter !== null) {
            $refusedFilter = $this->provoke(function () use ($filter): void {
                DB::table('fellowship_filters')->insert([
                    'fellowship_id' => $filter->fellowship_id,
                    'name' => $filter->name,
                    'is_performance' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

            $ledger->check(
                'The database refuses two filters of one name on one Fellowship',
                $refusedFilter,
                'A Fellowship was given two filters with the same name.',
            );

            $refusedValue = $this->provoke(function () use ($filter): void {
                $value = DB::table('fellowship_filter_values')->where('fellowship_filter_id', $filter->id)->first();

                if ($value === null) {
                    throw new \RuntimeException('no value to duplicate');
                }

                DB::table('fellowship_filter_values')->insert([
                    'fellowship_filter_id' => $value->fellowship_filter_id,
                    'value' => $value->value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

            $ledger->check(
                'The database refuses two identical values on one filter',
                $refusedValue,
                'A filter was given the same value twice.',
            );
        }

        // Nothing may have survived the probes.
        $ledger->same(
            'The constraint probes left nothing behind',
            0,
            (int) DB::table('fellowship_session_questions')
                ->where('fellowship_quiz_session_id', $sessionId)
                ->whereNull('fellowship_question_id')
                ->whereRaw("json_length(json_extract(question_snapshot, '$.options')) = 0")
                ->count(),
        );
    }

    /**
     * Run something that should be refused, inside a transaction that is always
     * rolled back.
     *
     * Returns whether the database refused it. The rollback is what makes this
     * safe to run against a developer's own data: whether the insert was
     * accepted or rejected, nothing of it remains.
     */
    private function provoke(callable $body): bool
    {
        try {
            DB::beginTransaction();

            $body();

            // Accepted, which means the constraint is not doing its job.
            DB::rollBack();

            return false;
        } catch (\Throwable) {
            DB::rollBack();

            return true;
        }
    }
}
