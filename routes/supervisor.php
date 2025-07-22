<?php

use App\Http\Controllers\SupervisorController;

Route::prefix('supervisor')->group(function () {
    Route::get('get_all_interns', [SupervisorController::class, 'getInternsBySupervisorSpecialty']);
})->middleware('auth:sanctum');
