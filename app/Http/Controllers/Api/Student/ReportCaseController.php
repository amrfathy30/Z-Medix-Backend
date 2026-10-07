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
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->integer('per_page', 15), 50);

        $reportCases = ReportCase::query()
            ->active()
            ->with('media')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return $this->paginatedResponse(ReportCaseResource::collection($reportCases));
    }
}
