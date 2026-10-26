<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Student\StudentSearchRequest;
use App\Services\Dashboard\StudentSearchService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class SearchController extends Controller
{
    use ApiResponse;

    public function __invoke(StudentSearchRequest $request, StudentSearchService $search): JsonResponse
    {
        return $this->successResponse($search->search($request->user(), $request->validated('q') ?? ''));
    }
}
