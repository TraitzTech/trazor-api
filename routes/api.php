<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SpecialtyController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('auth/me', [AuthController::class, 'getAuthUser']);
    Route::post('/update-device-token', [NotificationController::class, 'updateDeviceToken']);
    Route::post('/send-notification', [NotificationController::class, 'sendNotification']);
});

Route::prefix('specialties')->group(function () {
    Route::get('/', [SpecialtyController::class, 'index']);
    Route::get('/my-specialty', [SpecialtyController::class, 'mySpecialty']);
    Route::get('/{id}', [SpecialtyController::class, 'show']);
    Route::put('/{id}/edit', [SpecialtyController::class, 'update']);
    Route::delete('/{id}', [SpecialtyController::class, 'destroy']);

})->middleware('auth:sanctum');

require __DIR__.'/auth.php';
require __DIR__.'/admin.php';
