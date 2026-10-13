<?php

use App\Http\Controllers\Api\Student\ChapterController;
use App\Http\Controllers\Api\Student\ChapterPageController;
use App\Http\Controllers\Api\Student\ChapterQuizController;
use App\Http\Controllers\Api\Student\FellowshipAnswerController;
use App\Http\Controllers\Api\Student\FellowshipAvailabilityController;
use App\Http\Controllers\Api\Student\FellowshipController;
use App\Http\Controllers\Api\Student\FellowshipFilterController;
use App\Http\Controllers\Api\Student\FellowshipSessionController;
use App\Http\Controllers\Api\Student\FellowshipSessionResultController;
use App\Http\Controllers\Api\Student\FellowshipSessionStateController;
use App\Http\Controllers\Api\Student\HighlightController;
use App\Http\Controllers\Api\Student\NoteController;
use App\Http\Controllers\Api\Student\QuizAnswerController;
use App\Http\Controllers\Api\Student\QuizAttemptController;
use App\Http\Controllers\Api\Student\ReportCaseController;
use App\Http\Controllers\Api\Student\StudyResumeController;
use App\Http\Controllers\Api\Student\StudySessionController;
use Illuminate\Support\Facades\Route;

// Authenticated student endpoints. The group already carries `auth:sanctum`,
// so every route registered here is student-only.

Route::get('report-cases', [ReportCaseController::class, 'index'])->name('report-cases.index');

// The study flow. Every route validates that the requested content is
// accessible to this student right now; navigation itself is the frontend's.
Route::prefix('study')->name('study.')->group(function (): void {
    // Study time tracking only. Sessions are never auto-closed, and more than
    // one may be open at a time.
    Route::post('sessions', [StudySessionController::class, 'store'])->name('sessions.store');
    Route::post('sessions/{studySession}/end', [StudySessionController::class, 'end'])->name('sessions.end');

    // Where the student left off, kept apart from study sessions.
    Route::get('subjects/{subject}/resume', StudyResumeController::class)->name('subjects.resume');

    Route::get('chapters/{chapter}', [ChapterController::class, 'show'])->name('chapters.show');
    Route::get('chapter-pages/{chapterPage}', [ChapterPageController::class, 'show'])->name('chapter-pages.show');
    Route::post('chapter-pages/{chapterPage}/complete', [ChapterPageController::class, 'complete'])->name('chapter-pages.complete');

    // What the student marks up while reading. Both are anchored by the text
    // they selected, which is stored: the page they belong to is addressed on
    // creation and listing, the record itself once it exists.
    Route::get('chapter-pages/{chapterPage}/highlights', [HighlightController::class, 'index'])->name('chapter-pages.highlights.index');
    Route::post('chapter-pages/{chapterPage}/highlights', [HighlightController::class, 'store'])->name('chapter-pages.highlights.store');
    Route::delete('highlights/{highlight}', [HighlightController::class, 'destroy'])->name('highlights.destroy');

    Route::get('chapter-pages/{chapterPage}/notes', [NoteController::class, 'index'])->name('chapter-pages.notes.index');
    Route::post('chapter-pages/{chapterPage}/notes', [NoteController::class, 'store'])->name('chapter-pages.notes.store');
    Route::patch('notes/{note}', [NoteController::class, 'update'])->name('notes.update');
    Route::delete('notes/{note}', [NoteController::class, 'destroy'])->name('notes.destroy');

    // Starts a new attempt, or resumes the one left in progress.
    Route::post('chapters/{chapter}/quiz/start', ChapterQuizController::class)->name('chapters.quiz.start');
    Route::get('quiz-attempts/{quizAttempt}', QuizAttemptController::class)->name('quiz-attempts.show');
    // Addressed by the attempt's own question row, so an attempt stays
    // answerable after the live question it was captured from is deleted.
    Route::post('quiz-attempts/{quizAttempt}/questions/{quizAttemptQuestion}/answer', QuizAnswerController::class)
        ->name('quiz-attempts.questions.answer');
});

// The Fellowship question banks. A separate tree from the study flow above:
// a Fellowship has no chapters and no reading pages, and its sessions never mix
// with chapter quiz attempts.
//
// Sessions are addressed by their own id rather than nested under the
// Fellowship, since a session stays answerable on its own terms, and questions
// are addressed by their session row rather than by the live question — so a
// session survives the question behind it being edited or deleted.
Route::prefix('fellowships')->name('fellowships.')->group(function (): void {
    // The Question Bank screen lists Fellowships, not questions.
    Route::get('/', [FellowshipController::class, 'index'])->name('index');
    Route::get('{fellowship}', [FellowshipController::class, 'show'])->name('show');

    // What the Generate Session form offers, and what a given set of filters
    // would actually yield.
    Route::get('{fellowship}/filters', FellowshipFilterController::class)->name('filters');
    Route::post('{fellowship}/available-questions', FellowshipAvailabilityController::class)->name('available-questions');

    Route::get('{fellowship}/sessions', [FellowshipSessionController::class, 'index'])->name('sessions.index');
    Route::post('{fellowship}/sessions', [FellowshipSessionController::class, 'store'])->name('sessions.store');
});

Route::prefix('fellowship-sessions')->name('fellowship-sessions.')->group(function (): void {
    Route::get('{fellowshipQuizSession}', [FellowshipSessionController::class, 'show'])->name('show');
    Route::get('{fellowshipQuizSession}/result', FellowshipSessionResultController::class)->name('result');

    // The only two things that stop and start a session's clock.
    Route::post('{fellowshipQuizSession}/pause', [FellowshipSessionStateController::class, 'pause'])->name('pause');
    Route::post('{fellowshipQuizSession}/resume', [FellowshipSessionStateController::class, 'resume'])->name('resume');

    Route::post('{fellowshipQuizSession}/questions/{fellowshipSessionQuestion}/answer', FellowshipAnswerController::class)
        ->name('questions.answer');
});
