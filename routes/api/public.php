<?php

use App\Http\Controllers\Api\Public\AnalyticsController;
use App\Http\Controllers\Api\Public\Auth\ChangePasswordController;
use App\Http\Controllers\Api\Public\Auth\EmailVerificationController;
use App\Http\Controllers\Api\Public\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\Public\Auth\LoginController;
use App\Http\Controllers\Api\Public\Auth\LogoutController;
use App\Http\Controllers\Api\Public\Auth\RegisterController;
use App\Http\Controllers\Api\Public\Auth\ResendEmailOtpController;
use App\Http\Controllers\Api\Public\Auth\ResendPasswordResetOtpController;
use App\Http\Controllers\Api\Public\Auth\ResetPasswordController;
use App\Http\Controllers\Api\Public\Auth\VerifyEmailOtpController;
use App\Http\Controllers\Api\Public\Auth\VerifyPasswordResetOtpController;
use App\Http\Controllers\Api\Public\BlogController;
use App\Http\Controllers\Api\Public\ContactController;
use App\Http\Controllers\Api\Public\CountryController;
use App\Http\Controllers\Api\Public\FaqController;
use App\Http\Controllers\Api\Public\HomeController;
use App\Http\Controllers\Api\Public\MarketingPixelController;
use App\Http\Controllers\Api\Public\PageController;
use App\Http\Controllers\Api\Public\PageSectionController;
use App\Http\Controllers\Api\Public\ProfileController;
use App\Http\Controllers\Api\Public\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => response()->json(['message' => 'pong']))->name('ping');

Route::prefix('auth')->name('auth.')->group(function (): void {
    // Student registration + email OTP activation. Throttles here are coarse abuse
    // protection; the per-code attempt limit, resend cooldown and hourly send
    // allowance are enforced by EmailOtpService.
    Route::post('register', RegisterController::class)
        ->middleware('throttle:10,1')
        ->name('register');
    Route::post('verify-otp', VerifyEmailOtpController::class)
        ->middleware('throttle:10,1')
        ->name('verify-otp');
    Route::post('resend-otp', ResendEmailOtpController::class)
        ->middleware('throttle:10,1')
        ->name('resend-otp');

    Route::post('login', LoginController::class)->name('login');

    // Student password reset is email-OTP based. The Filament admin panel keeps its
    // own link-based reset through the `admins` password broker.
    Route::post('forgot-password', ForgotPasswordController::class)
        ->middleware('throttle:10,1')
        ->name('forgot-password');
    Route::post('forgot-password/resend', ResendPasswordResetOtpController::class)
        ->middleware('throttle:10,1')
        ->name('forgot-password.resend');
    Route::post('forgot-password/verify-otp', VerifyPasswordResetOtpController::class)
        ->middleware('throttle:10,1')
        ->name('forgot-password.verify-otp');
    Route::post('reset-password', ResetPasswordController::class)
        ->middleware('throttle:10,1')
        ->name('reset-password');

    Route::get('verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->name('verify-email');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('logout', LogoutController::class)->name('logout');
        Route::post('email/verification-notification', [EmailVerificationController::class, 'send'])
            ->name('email.send');
        Route::post('change-password', ChangePasswordController::class)->name('change-password');
    });
});

Route::middleware('auth:sanctum')->name('profile.')->group(function (): void {
    Route::get('profile', [ProfileController::class, 'show'])->name('show');
    Route::post('profile', [ProfileController::class, 'update'])->name('update');
});

// ── CMS Public Endpoints ──────────────────────────────────────────────────────
Route::get('home', HomeController::class)->name('home');
Route::get('pages/{slug}', [PageController::class, 'show'])->name('pages.show');
Route::get('pages/{page_key}/sections', [PageSectionController::class, 'index'])->name('pages.sections.index');
Route::get('blog-categories', [BlogController::class, 'categories'])->name('blog-categories.index');
Route::get('blogs', [BlogController::class, 'index'])->name('blogs.index');
Route::get('blogs/{slug}', [BlogController::class, 'show'])->name('blogs.show');
Route::get('faq-categories', [FaqController::class, 'categories'])->name('faq-categories.index');
Route::get('faqs', [FaqController::class, 'index'])->name('faqs.index');
Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
Route::get('marketing/analytics', [AnalyticsController::class, 'index'])->name('marketing.analytics.index');
Route::get('marketing/pixels', [MarketingPixelController::class, 'index'])->name('marketing.pixels.index');
Route::post('contact', [ContactController::class, 'store'])->name('contact.store');
Route::get('countries', [CountryController::class, 'index'])->name('countries.index');
Route::get('countries/{country}/cities', [CountryController::class, 'cities'])->name('countries.cities.index');
