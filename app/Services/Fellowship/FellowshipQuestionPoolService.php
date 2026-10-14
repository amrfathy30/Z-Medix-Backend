<?php

namespace App\Services\Fellowship;

use App\Enums\FellowshipQuestionStatusFilter;
use App\Models\Fellowship;
use App\Models\FellowshipQuestion;
use App\Models\FellowshipSessionQuestion;
use App\Models\User;
use App\Support\Fellowship\SessionGenerationCriteria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The questions a student is eligible to be served from a Fellowship.
 *
 * One query builder, used for both the count shown before generation and the
 * draw that generates — so the number the student is told is available is
 * exactly the number that could be drawn, and a session is never created
 * partially filled.
 *
 * The filter algebra lives here: values chosen within one filter are
 * alternatives, different filters all have to hold, and a filter the student
 * left alone does not restrict anything. The Performance Topic is matched on the
 * question's own column rather than through the pivot, because that is where a
 * question's single required topic is stored.
 */
class FellowshipQuestionPoolService
{
    /**
     * @return Builder<FellowshipQuestion>
     */
    public function query(Fellowship $fellowship, User $student, SessionGenerationCriteria $criteria): Builder
    {
        $query = FellowshipQuestion::query()
            ->forFellowship($fellowship->getKey())
            // A question with fewer than two options or no correct answer could
            // not be answered; the importer rejects such rows, and this makes
            // sure a hand-edited one cannot reach a student either.
            ->whereHas('options', fn (Builder $options): Builder => $options->where('is_correct', true))
            ->has('options', '>=', 2);

        if ($criteria->hasDifficulties()) {
            $query->whereIn('difficulty', $criteria->difficultyValues());
        }

        $performanceFilterId = $fellowship->performance_filter_id;

        foreach ($criteria->filterValueIds as $filterId => $valueIds) {
            if ($performanceFilterId !== null && $filterId === $performanceFilterId) {
                // The required classification: one column on the question, so
                // OR within the filter is a plain whereIn.
                $query->whereIn('performance_filter_value_id', $valueIds);

                continue;
            }

            // One whereHas per filter is the AND across filters; the whereIn
            // inside it is the OR within one.
            $query->whereHas(
                'filterValues',
                fn (Builder $pivot): Builder => $pivot
                    ->where('fellowship_filter_id', $filterId)
                    ->whereIn('fellowship_filter_value_id', $valueIds),
            );
        }

        $this->applyQuestionStatus($query, $fellowship, $student, $criteria->questionStatus);

        return $query;
    }

    /**
     * How many questions this student could be served under these criteria.
     */
    public function count(Fellowship $fellowship, User $student, SessionGenerationCriteria $criteria): int
    {
        return $this->query($fellowship, $student, $criteria)->count();
    }

    /**
     * Draw the requested number at random.
     *
     * The draw is of distinct questions — a session never serves the same
     * question twice — and the order it comes back in becomes the session's
     * fixed order.
     *
     * @return Collection<int, FellowshipQuestion>
     */
    public function draw(Fellowship $fellowship, User $student, SessionGenerationCriteria $criteria, int $count): Collection
    {
        return $this->query($fellowship, $student, $criteria)
            ->with(['options', 'performanceTopic.filter', 'filterValues.filter', 'filterValues.value'])
            ->inRandomOrder()
            ->limit(max(0, $count))
            ->get();
    }

    /**
     * Narrow to the questions this student has, or has not, confirmed an answer
     * to before — in any session of this Fellowship, not just the current one.
     *
     * Matched on the session question's `fellowship_question_id`, which is the
     * traceability link back to the live question. A question whose row has
     * since been force-deleted is therefore treated as unanswered, which is the
     * only honest answer: there is nothing left to match it to.
     *
     * @param  Builder<FellowshipQuestion>  $query
     */
    private function applyQuestionStatus(
        Builder $query,
        Fellowship $fellowship,
        User $student,
        FellowshipQuestionStatusFilter $status,
    ): void {
        if ($status === FellowshipQuestionStatusFilter::All) {
            return;
        }

        $answeredIds = FellowshipSessionQuestion::query()
            ->answered()
            ->whereNotNull('fellowship_question_id')
            ->whereHas(
                'session',
                fn (Builder $session): Builder => $session
                    ->where('user_id', $student->getKey())
                    ->where('fellowship_id', $fellowship->getKey()),
            )
            ->select('fellowship_question_id');

        match ($status) {
            FellowshipQuestionStatusFilter::Answered => $query->whereIn('id', $answeredIds),
            FellowshipQuestionStatusFilter::Unanswered => $query->whereNotIn('id', $answeredIds),
            FellowshipQuestionStatusFilter::All => null,
        };
    }
}
