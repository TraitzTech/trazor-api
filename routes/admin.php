<?php

// routes/api.php - Add these routes to your existing admin group
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\SpecialtyController;

Route::prefix('admin')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->name('admin.login');

    Route::middleware(['auth:sanctum'])->group(function () {
        // Existing routes
        Route::get('/interns', [AdminController::class, 'getAllInterns']);

        // New routes for complete user management
        Route::get('/supervisors', [AdminController::class, 'getAllSupervisors']);
        Route::get('/admins', [AdminController::class, 'getAllAdmins']);
        Route::get('/users', [AdminController::class, 'getAllUsers']); // Alternative: get all users in one call
        Route::get('/users/{id}', [AdminController::class, 'showUser']);
        Route::put('/users/{id}', [AdminController::class, 'updateUser']);

        Route::prefix('user')->group(function () {
            Route::get('/{userId}/activities', [AdminController::class, 'getUserActivities']);

        });

        Route::patch('/users/{id}/toggle-status', [AdminController::class, 'toggleUserStatus']);

        Route::prefix('specialties')->group(function () {
            Route::post('create', [SpecialtyController::class, 'store'])->name('specialties.store');
            Route::put('update/{id}', [SpecialtyController::class, 'update'])->name('specialties.update');
            Route::delete('delete/{id}', [SpecialtyController::class, 'delete'])->name('specialties.delete');
        });
    });
});
