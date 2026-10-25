<?php

namespace App\Http\Controllers\Api\Student;

use App\Exceptions\Learning\StudyException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Student\BookPageResource;
use App\Models\BookPage;
use App\Services\Learning\StudyAccessService;
use App\Support\Api\ApiResponse;
use App\Support\Api\HandlesStudyExceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookPageController extends Controller
{
    use ApiResponse, HandlesStudyExceptions;

    public function __construct(private StudyAccessService $access) {}

    public function show(Request $request, BookPage $bookPage): JsonResponse
    {
        try {
            $this->access->assertCanAccessBookPage($request->user(), $bookPage);
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        return $this->successResponse(new BookPageResource(
            $bookPage,
            $bookPage->positionInBook(),
        ));
    }
}
