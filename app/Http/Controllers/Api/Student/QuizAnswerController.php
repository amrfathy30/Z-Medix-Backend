<?php

namespace App\Http\Controllers\Api\Student;

use App\Exceptions\Learning\StudyException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Student\AnswerQuizQuestionRequest;
use App\Http\Resources\Api\Student\QuizAttemptQuestionResource;
use App\Http\Resources\Api\Student\QuizAttemptResource;
use App\Models\QuizAttempt;
use App\Models\QuizAttemptQuestion;
use App\Services\Learning\QuizAttemptService;
use App\Support\Api\ApiResponse;
use App\Support\Api\HandlesStudyExceptions;
use Illuminate\Http\JsonResponse;

/**
 * Submitting an answer. The answer is final, and the response is the first
 * point at which the correct option is disclosed.
 *
 * The question is addressed by its attempt row rather than by the live question,
 * so an attempt stays answerable after the original question is deleted. The
 * option is graded against that row's snapshot.
 *
 * Answering the last open question completes the attempt and the chapter, which
 * the returned attempt state reflects.
 */
class QuizAnswerController extends Controller
{
    use ApiResponse, HandlesStudyExceptions;

    public function __invoke(
        AnswerQuizQuestionRequest $request,
        QuizAttempt $quizAttempt,
        QuizAttemptQuestion $quizAttemptQuestion,
        QuizAttemptService $attempts,
    ): JsonResponse {
        try {
            $answered = $attempts->answer(
                $request->user(),
                $quizAttempt,
                $quizAttemptQuestion,
                $request->selectedOptionKey(),
            );
        } catch (StudyException $exception) {
            return $this->studyErrorResponse($exception);
        }

        $quizAttempt->load(['questions', 'quiz']);

        return $this->successResponse([
            'question' => new QuizAttemptQuestionResource($answered),
            'attempt' => new QuizAttemptResource($quizAttempt, $attempts->tally($quizAttempt)),
        ], 'Answer submitted.');
    }
}
