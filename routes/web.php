<?php

use App\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/sitemap.xml', [SitemapController::class, 'sitemap'])->name('seo.sitemap');
Route::get('/robots.txt', [SitemapController::class, 'robots'])->name('seo.robots');
if (app()->environment('local')) {
    // System Debug Dashboard
    Route::prefix('debug-center')->group(function () {
        Route::get('/', [DebugController::class, 'index'])->name('debug.index');
        Route::post('/migrate', [DebugController::class, 'migrate'])->name('debug.migrate');
        Route::post('/seed', [DebugController::class, 'seed'])->name('debug.seed');
        Route::post('/clear-logs', [DebugController::class, 'clearLogs'])->name('debug.clear-logs');
    });

    // System Debug Logs
    Route::get('/debug-logs', [SystemLogController::class, 'index']);
    Route::get('/debug-logs/clear', [SystemLogController::class, 'clear']);

    // .env Manager
    Route::get('/env-manager', [SystemLogController::class, 'showEnv']);
    Route::post('/env-manager/update', [SystemLogController::class, 'updateEnv']);
}
