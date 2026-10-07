<?php

use App\Http\Middleware\SetPublicLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware(['api', SetPublicLocale::class])
                ->prefix('api/public')
                ->name('public.')
                ->group(base_path('routes/api/public.php'));

            Route::middleware(['api', SetPublicLocale::class, 'auth:sanctum'])
                ->prefix('api/student')
                ->name('student.')
                ->group(base_path('routes/api/student.php'));

            Route::middleware('api')
                ->prefix('api/admin')
                ->name('admin.')
                ->group(base_path('routes/api/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
