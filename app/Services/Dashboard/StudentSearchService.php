<?php

namespace App\Services\Dashboard;

use App\Enums\PlanFeature;
use App\Models\Fellowship;
use App\Models\ReportCase;
use App\Models\Subject;
use App\Models\User;
use App\Services\Subscription\FeatureAccessService;
use Illuminate\Database\Eloquent\Builder;

class StudentSearchService
{
    private const RESULTS_PER_CATEGORY = 20;

    public function __construct(private FeatureAccessService $features) {}

    /** @return array{subjects: list<array{id: int, name: string}>, fellowships: list<array{id: int, name: string}>, report_cases: list<array{id: int, title: string}>} */
    public function search(User $student, string $term): array
    {
        $results = ['subjects' => [], 'fellowships' => [], 'report_cases' => []];

        if ($term === '') {
            return $results;
        }

        $codes = $this->features->denialCodesFor($student);
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';

        if ($codes[PlanFeature::Library->value] === null) {
            $results['subjects'] = $this->matches(Subject::query()->published(), 'name', $pattern);
        }
        if ($codes[PlanFeature::QuestionBank->value] === null) {
            $results['fellowships'] = $this->matches(Fellowship::query()->active(), 'name', $pattern);
        }
        if ($codes[PlanFeature::CaseReport->value] === null) {
            $results['report_cases'] = $this->matches(ReportCase::query()->active(), 'title', $pattern);
        }

        return $results;
    }

    /** @return list<array<string, int|string>> */
    private function matches(Builder $query, string $column, string $pattern): array
    {
        return $query->whereRaw("{$column} LIKE ? ESCAPE '!'", [$pattern])
            ->orderBy($column)->orderBy('id')->limit(self::RESULTS_PER_CATEGORY)
            ->get(['id', $column])->map(fn ($record): array => [
                'id' => $record->getKey(),
                $column => $record->getAttribute($column),
            ])->all();
    }
}
