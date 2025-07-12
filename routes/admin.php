<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\SpecialtyController;

Route::prefix('admin')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->name('admin.login');

    Route::middleware(['auth:sanctum'])->group(function () {
        Route::get('/interns', [AdminController::class, 'getAllInterns']);

        Route::prefix('specialties')->group(function () {
            Route::post('create', [SpecialtyController::class, 'store'])->name('specialties.store');
            Route::put('update/{id}', [SpecialtyController::class, 'update'])->name('specialties.update');
            Route::delete('delete/{id}', [SpecialtyController::class, 'delete'])->name('specialties.delete');
        });
    });
});
