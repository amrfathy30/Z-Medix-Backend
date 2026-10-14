<?php

namespace App\Http\Controllers\Api\Student;

use App\Exceptions\Learning\StudyException;
use App\Http\Controllers\Controller;
use App\Models\StudentPrivateBook;
use App\Services\Learning\StudentPrivateBookService;
use App\Support\Api\ApiResponse;
use App\Support\Api\HandlesStudyExceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Handing a student their own uploaded PDF.
 *
 * This route is the only way the file can be read. It lives on the private disk
 * — outside the public storage link — so there is no URL that serves it without
 * passing through Sanctum and the ownership check here. Another student's book
 * is refused as not found rather than forbidden, which is also what keeps the
 * existence of someone else's upload unobservable.
 *
 * `?disposition=inline` streams the file for an in-app reader; the default is a
 * download.
 */
class PrivateBookPdfController extends Controller
{
    use ApiResponse, HandlesStudyExceptions;

    public function __construct(private StudentPrivateBookService $privateBooks) {}

    public function __invoke(Request $request, StudentPrivateBook $privateBook): StreamedResponse|JsonResponse
    {
        try {
            $media = $this->privateBooks->pdfFor($request->user(), $privateBook);
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        return $request->string('disposition')->toString() === 'inline'
            ? $media->toInlineResponse($request)
            : $media->toResponse($request);
    }
}
