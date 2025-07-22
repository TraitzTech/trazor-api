<?php

use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\LogbookController;
use App\Http\Controllers\LogbookReviewController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SpecialtyController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('auth/me', [AuthController::class, 'getAuthUser']);
    Route::post('/update-device-token', [NotificationController::class, 'updateDeviceToken']);
    Route::post('/send-notification', [NotificationController::class, 'sendNotification']);

    Route::prefix('announcements')->group(function () {
        Route::get('/', [AnnouncementController::class, 'index']);
        Route::post('/', [AnnouncementController::class, 'store']);
        Route::get('/created-by', [AnnouncementController::class, 'getByCreator']);
        Route::get('/for-intern', [AnnouncementController::class, 'getForIntern']);

    });

    Route::prefix('specialties')->group(function () {
        Route::get('/', [SpecialtyController::class, 'index']);
        Route::get('/my-specialty', [SpecialtyController::class, 'mySpecialty']);
        Route::get('/{id}', [SpecialtyController::class, 'show']);
        Route::put('/{id}/edit', [SpecialtyController::class, 'update']);
        Route::delete('/{id}', [SpecialtyController::class, 'destroy']);

    });

    Route::apiResource('logbooks', LogbookController::class);
    Route::post('/logbooks/{id}/review', [LogbookReviewController::class, 'store']);
    Route::get('/intern/logbooks', [LogbookController::class, 'internLogbooks'])->middleware('auth:sanctum');

});

require __DIR__.'/auth.php';
require __DIR__.'/admin.php';
require __DIR__.'/supervisor.php';
