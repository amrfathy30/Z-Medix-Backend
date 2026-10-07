<?php

use App\Http\Controllers\Api\Student\ChapterController;
use App\Http\Controllers\Api\Student\ChapterPageController;
use App\Http\Controllers\Api\Student\ChapterQuizController;
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
