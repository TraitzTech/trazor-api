<?php

use App\Http\Controllers\SupervisorController;

Route::prefix('supervisor')->middleware('auth:sanctum')->group(function () {
    // Dashboard
    Route::get('dashboard', [SupervisorController::class, 'getDashboard']);

    // Interns
    Route::get('interns', [SupervisorController::class, 'getInternsBySupervisorSpecialty']);
    Route::get('get_all_interns', [SupervisorController::class, 'getInternsBySupervisorSpecialty']); // Keep for backward compatibility

    // Announcements
    Route::get('announcements', [SupervisorController::class, 'getAnnouncements']);

    // Tasks
    Route::get('tasks', [SupervisorController::class, 'getTasks']);
    Route::get('tasks/{taskId}', [SupervisorController::class, 'getTask']);

    // Task Submissions
    Route::get('submissions', [SupervisorController::class, 'getTaskSubmissions']);
    Route::get('tasks/{taskId}/submissions', [SupervisorController::class, 'getTaskSubmissionsForTask']);
});
