<?php

use App\Http\Controllers\Api\FcmTokenController;
use App\Http\Controllers\Api\FcmTopicController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\Student\BookController;
use App\Http\Controllers\Api\Student\BookmarkController;
use App\Http\Controllers\Api\Student\BookPageController;
use App\Http\Controllers\Api\Student\ChapterController;
use App\Http\Controllers\Api\Student\ChapterPageController;
use App\Http\Controllers\Api\Student\ChapterQuizController;
use App\Http\Controllers\Api\Student\DashboardController;
use App\Http\Controllers\Api\Student\FellowshipAnswerController;
use App\Http\Controllers\Api\Student\FellowshipAvailabilityController;
use App\Http\Controllers\Api\Student\FellowshipController;
use App\Http\Controllers\Api\Student\FellowshipFilterController;
use App\Http\Controllers\Api\Student\FellowshipSessionController;
use App\Http\Controllers\Api\Student\FellowshipSessionResultController;
use App\Http\Controllers\Api\Student\FellowshipSessionStateController;
use App\Http\Controllers\Api\Student\HighlightController;
use App\Http\Controllers\Api\Student\NoteController;
use App\Http\Controllers\Api\Student\PlanController;
use App\Http\Controllers\Api\Student\PrivateBookController;
use App\Http\Controllers\Api\Student\PrivateBookPdfController;
use App\Http\Controllers\Api\Student\PromoCodeController;
use App\Http\Controllers\Api\Student\QuizAnswerController;
use App\Http\Controllers\Api\Student\QuizAttemptController;
use App\Http\Controllers\Api\Student\QuizAttemptHistoryController;
use App\Http\Controllers\Api\Student\ReportCaseController;
use App\Http\Controllers\Api\Student\StudyResumeController;
use App\Http\Controllers\Api\Student\StudySessionController;
use App\Http\Controllers\Api\Student\SubjectController;
use App\Http\Controllers\Api\Student\SubjectStatisticsController;
use App\Http\Controllers\Api\Student\SubscriptionController;
use App\Http\Controllers\Api\Student\SubscriptionRequestController;
use Illuminate\Support\Facades\Route;

// Authenticated student endpoints. The group already carries `auth:sanctum`,
// so every route registered here is student-only.

// The home screen, in one read: what the student can continue, what is on
// offer, and what they last did. A summary only — every section's items are
// opened through the dedicated endpoints below, and nothing here writes.
Route::get('dashboard', DashboardController::class)->name('dashboard');

// Plans and the student's own subscription. Deliberately outside every
// `feature:*` gate below: a student with no plan reaches these, and only these,
// which is how they find a plan, ask for it and see where the request stands.
Route::get('plans', [PlanController::class, 'index'])->name('plans.index');
Route::get('subscription', [SubscriptionController::class, 'show'])->name('subscription.show');
Route::post('subscription/promo-code/validate', PromoCodeController::class)->middleware('throttle:30,1')->name('subscription.promo-code.validate');
Route::post('subscription-requests', [SubscriptionRequestController::class, 'store'])->name('subscription-requests.store');

// Everything below is gated by the student's plan. The `feature` middleware
// responds with HTTP 200 and `SUBSCRIPTION_REQUIRED` when there is no active subscription
// and `FEATURE_NOT_IN_PLAN` when the plan lacks the feature, so a route here
// is never reachable on frontend state alone.
//
//   case_report   -> Case Report
//   library       -> study content and the student's own private books
//   question_bank -> Fellowships
//   ai            -> no routes yet; the AI module's routes will use feature:ai

Route::get('report-cases', [ReportCaseController::class, 'index'])
    ->middleware('feature:case_report')
    ->name('report-cases.index');

Route::get('books', [BookController::class, 'collection'])
    ->middleware('feature:library')
    ->name('books.index');

// The study flow. Every route validates that the requested content is
// accessible to this student right now; navigation itself is the frontend's.
Route::prefix('study')->middleware('feature:library')->name('study.')->group(function (): void {
    // Study time tracking only. Sessions are never auto-closed, and more than
    // one may be open at a time.
    Route::post('sessions', [StudySessionController::class, 'store'])->name('sessions.store');
    Route::post('sessions/{studySession}/end', [StudySessionController::class, 'end'])->name('sessions.end');

    // Where the study flow starts. A subject holds two independent kinds of
    // content — its chapter track and its platform books — so its details
    // carry both, and neither is nested inside the other.
    Route::get('subjects', [SubjectController::class, 'index'])->name('subjects.index');
    Route::get('subjects/{subject}', [SubjectController::class, 'show'])->name('subjects.show');

    // Results and statistics for one subject, counted from the student's own
    // recorded activity. The subject's overall quiz result is part of this
    // payload rather than a second endpoint that could disagree with it.
    Route::get('subjects/{subject}/statistics', SubjectStatisticsController::class)->name('subjects.statistics');

    // Where the student left off, kept apart from study sessions.
    Route::get('subjects/{subject}/resume', StudyResumeController::class)->name('subjects.resume');

    // Platform books: reference material beside the chapter track, never
    // sequenced behind it. A PDF book is opened as a file, a content book as
    // pages.
    Route::get('subjects/{subject}/books', [BookController::class, 'index'])->name('subjects.books.index');
    Route::get('books/{book}', [BookController::class, 'show'])->name('books.show');
    Route::get('books/{book}/pages', [BookController::class, 'pages'])->name('books.pages');
    Route::get('book-pages/{bookPage}', [BookPageController::class, 'show'])->name('book-pages.show');

    // The student's dedicated Highlights, Notes and Bookmarks area: what they
    // marked up across every subject, not on one page. Registered before the
    // page-scoped routes below so the collection paths are unambiguous.
    Route::get('highlights', [HighlightController::class, 'collection'])->name('highlights.collection');
    Route::get('notes', [NoteController::class, 'collection'])->name('notes.collection');
    Route::get('bookmarks', [BookmarkController::class, 'collection'])->name('bookmarks.collection');

    Route::get('chapters/{chapter}', [ChapterController::class, 'show'])->name('chapters.show');
    Route::get('chapter-pages/{chapterPage}', [ChapterPageController::class, 'show'])->name('chapter-pages.show');
    Route::post('chapter-pages/{chapterPage}/complete', [ChapterPageController::class, 'complete'])->name('chapter-pages.complete');

    // What the student marks up while reading. Both are anchored by the text
    // they selected, which is stored: the page they belong to is addressed on
    // creation and listing, the record itself once it exists.
    Route::get('chapter-pages/{chapterPage}/highlights', [HighlightController::class, 'index'])->name('chapter-pages.highlights.index');
    Route::post('chapter-pages/{chapterPage}/highlights', [HighlightController::class, 'store'])->name('chapter-pages.highlights.store');
    Route::delete('highlights/{highlight}', [HighlightController::class, 'destroy'])->name('highlights.destroy');

    // A bookmark marks a place to come back to. It carries no writing of the
    // student's own, so there is no update: it is created and removed only.
    Route::get('chapter-pages/{chapterPage}/bookmarks', [BookmarkController::class, 'index'])->name('chapter-pages.bookmarks.index');
    Route::post('chapter-pages/{chapterPage}/bookmarks', [BookmarkController::class, 'store'])->name('chapter-pages.bookmarks.store');
    Route::delete('bookmarks/{bookmark}', [BookmarkController::class, 'destroy'])->name('bookmarks.destroy');

    Route::get('chapter-pages/{chapterPage}/notes', [NoteController::class, 'index'])->name('chapter-pages.notes.index');
    Route::post('chapter-pages/{chapterPage}/notes', [NoteController::class, 'store'])->name('chapter-pages.notes.store');
    Route::patch('notes/{note}', [NoteController::class, 'update'])->name('notes.update');
    Route::delete('notes/{note}', [NoteController::class, 'destroy'])->name('notes.destroy');

    // Starts a new attempt, or resumes the one left in progress.
    Route::post('chapters/{chapter}/quiz/start', ChapterQuizController::class)->name('chapters.quiz.start');
    // Every attempt the student has made on this chapter's quiz, newest first:
    // how they find the past attempts they can review. Attempts are kept
    // permanently, so this list only grows.
    Route::get('chapters/{chapter}/quiz/attempts', QuizAttemptHistoryController::class)->name('chapters.quiz.attempts');
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
Route::prefix('fellowships')->middleware('feature:question_bank')->name('fellowships.')->group(function (): void {
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

Route::prefix('fellowship-sessions')->middleware('feature:question_bank')->name('fellowship-sessions.')->group(function (): void {
    Route::get('{fellowshipQuizSession}', [FellowshipSessionController::class, 'show'])->name('show');
    Route::get('{fellowshipQuizSession}/result', FellowshipSessionResultController::class)->name('result');

    // The only two things that stop and start a session's clock.
    Route::post('{fellowshipQuizSession}/pause', [FellowshipSessionStateController::class, 'pause'])->name('pause');
    Route::post('{fellowshipQuizSession}/resume', [FellowshipSessionStateController::class, 'resume'])->name('resume');

    Route::post('{fellowshipQuizSession}/questions/{fellowshipSessionQuestion}/answer', FellowshipAnswerController::class)
        ->name('questions.answer');
});

// The student's own library: PDFs they uploaded themselves, each filed under a
// subject. Deliberately outside the `study` tree — these are the student's
// files, not platform study content, and they never share a record, a disk or
// an access rule with a platform book.
//
// The PDF lives on the private disk, so the download route below is the only
// way it can be read: there is no public URL to a student's upload.
Route::prefix('private-books')->middleware('feature:library')->name('private-books.')->group(function (): void {
    Route::get('/', [PrivateBookController::class, 'index'])->name('index');
    Route::post('/', [PrivateBookController::class, 'store'])->name('store');
    Route::get('{privateBook}', [PrivateBookController::class, 'show'])->name('show');
    Route::patch('{privateBook}', [PrivateBookController::class, 'update'])->name('update');
    Route::delete('{privateBook}', [PrivateBookController::class, 'destroy'])->name('destroy');

    Route::get('{privateBook}/pdf', PrivateBookPdfController::class)->name('pdf');
});

// Device (FCM) Tokens & Topics
Route::post('fcm-tokens', [FcmTokenController::class, 'store'])->name('fcm-tokens.store');
Route::delete('fcm-tokens', [FcmTokenController::class, 'destroy'])->name('fcm-tokens.destroy');
Route::post('fcm-tokens/topic/subscribe', [FcmTopicController::class, 'subscribe'])->name('fcm-tokens.topic.subscribe');
Route::post('fcm-tokens/topic/unsubscribe', [FcmTopicController::class, 'unsubscribe'])->name('fcm-tokens.topic.unsubscribe');

// Notifications Center
Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread-count');
Route::patch('notifications/read-all', [NotificationController::class, 'markAllAsRead'])->name('notifications.read-all');
Route::patch('notifications/{notification}/read', [NotificationController::class, 'markAsRead'])->name('notifications.read');
