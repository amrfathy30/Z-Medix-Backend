<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Student\ReportCaseResource;
use App\Models\ReportCase;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportCaseController extends Controller
{
    use ApiResponse;

    /**
     * Active report cases, newest first. Inactive ones are filtered in the
     * query, so they never reach a student.
     *
     * `search` narrows the list to cases whose title, description or source
     * contains it.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $perPage = min((int) $request->integer('per_page', 15), 50);

        $reportCases = ReportCase::query()
            ->active()
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = '%'.$request->string('search')->toString().'%';

                // Grouped, so the OR can never escape the `active` filter.
                $query->where(function ($match) use ($search): void {
                    $match->where('title', 'LIKE', $search)
                        ->orWhere('description', 'LIKE', $search)
                        ->orWhere('source', 'LIKE', $search);
                });
            })
            ->with('media')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return $this->paginatedResponse(ReportCaseResource::collection($reportCases));
    }
}
