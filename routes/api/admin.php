<?php

use App\Http\Controllers\Api\FcmTokenController;
use App\Http\Controllers\Api\FcmTopicController;
use App\Http\Controllers\Api\NotificationController;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => response()->json(['message' => 'pong']))->name('ping');

Route::middleware('auth:sanctum')->group(function (): void {
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
});
