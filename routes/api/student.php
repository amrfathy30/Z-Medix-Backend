<?php

use App\Http\Controllers\Api\Student\ReportCaseController;
use Illuminate\Support\Facades\Route;

// Authenticated student endpoints. The group already carries `auth:sanctum`,
// so every route registered here is student-only.

Route::get('report-cases', [ReportCaseController::class, 'index'])->name('report-cases.index');
