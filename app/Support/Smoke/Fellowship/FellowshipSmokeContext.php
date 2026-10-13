<?php

namespace App\Support\Smoke\Fellowship;

use App\Models\Admin;
use App\Models\Fellowship;
use App\Models\User;
use App\Support\Smoke\SmokeApiClient;
use App\Support\Smoke\SmokeLedger;

/**
 * What the Fellowship smoke scenarios share.
 *
 * The run is a sequence of scenarios that build on each other — the import
 * scenario produces the question bank the student scenario studies, and the
 * student scenario produces the answers the analytics scenario reads — so the
 * records they create have to be passed along rather than rediscovered. This
 * holds them, together with the ledger every scenario records into and the API
 * client the student requests go through.
 *
 * Deliberately a plain mutable bag. A scenario that could not produce its part
 * leaves the field null, and the scenario after it reports its checks as skipped
 * rather than failing on a missing id — which is the distinction the final
 * report depends on.
 */
class FellowshipSmokeContext
{
    public ?Fellowship $fellowship = null;

    /** A second Fellowship, used only to prove isolation between them. */
    public ?Fellowship $otherFellowship = null;

    public ?Admin $admin = null;

    public ?User $student = null;

    public ?User $otherStudent = null;

    public ?string $studentToken = null;

    public ?string $otherStudentToken = null;

    /**
     * The generated workbooks, keyed A, B, C, D1…D5.
     *
     * @var array<string, array{path: string, rows: int, readable_rows: int, headers: list<string>, note: string}>
     */
    public array $workbooks = [];

    /**
     * Import ids this run created, keyed by the workbook they came from.
     *
     * @var array<string, int>
     */
    public array $imports = [];

    /**
     * Session ids this run created, keyed by the scenario that made them.
     *
     * @var array<string, int>
     */
    public array $sessions = [];

    /**
     * How many live questions the run force-deleted on purpose, to prove a
     * session survives its question being destroyed.
     *
     * Counted because the destruction has a visible consequence elsewhere: the
     * import row that created the question has its link cleared by the schema's
     * `SET NULL`, so the integrity sweep must expect exactly this many such
     * rows rather than none.
     */
    public int $forceDeletedQuestions = 0;

    /**
     * Row counts of every Fellowship table before the run touched anything.
     *
     * @var array<string, int>
     */
    public array $baselineCounts = [];

    /**
     * Counts of rows the run does not own, before it started.
     *
     * @var array<string, int>
     */
    public array $baselineForeignCounts = [];

    public function __construct(
        public readonly SmokeLedger $ledger,
        public readonly SmokeApiClient $api,
    ) {}

    /**
     * The Fellowship ids this run owns.
     *
     * @return list<int>
     */
    public function fellowshipIds(): array
    {
        return array_values(array_filter([
            $this->fellowship?->getKey(),
            $this->otherFellowship?->getKey(),
        ]));
    }

    /**
     * The user ids this run owns.
     *
     * @return list<int>
     */
    public function userIds(): array
    {
        return array_values(array_filter([
            $this->student?->getKey(),
            $this->otherStudent?->getKey(),
        ]));
    }

    /**
     * Authenticate the API client as the main smoke student. Returns false when
     * there is no token, so a scenario can skip rather than fail.
     */
    public function asStudent(): bool
    {
        if ($this->studentToken === null) {
            return false;
        }

        $this->api->authenticateWith($this->studentToken);

        return true;
    }

    public function asOtherStudent(): bool
    {
        if ($this->otherStudentToken === null) {
            return false;
        }

        $this->api->authenticateWith($this->otherStudentToken);

        return true;
    }

    public function asGuest(): void
    {
        $this->api->forgetToken();
    }
}
