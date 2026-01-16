<?php

use App\Http\Controllers\SupervisorController;

Route::prefix('supervisor')->middleware('auth:sanctum')->group(function () {
    Route::get('get_all_interns', [SupervisorController::class, 'getInternsBySupervisorSpecialty']);
    
    // Announcements for supervisor
    Route::get('announcements', [SupervisorController::class, 'getAnnouncements']);
});
