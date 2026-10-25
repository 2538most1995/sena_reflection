<?php

use App\Http\Controllers\StudentApiSettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    Route::get('/admin/student-api', [StudentApiSettingsController::class, 'index'])->name('admin.student-api');
    Route::post('/admin/student-api', [StudentApiSettingsController::class, 'store'])->middleware('throttle:10,1')->name('admin.student-api.store');
    Route::post('/admin/student-api/test', [StudentApiSettingsController::class, 'test'])->middleware('throttle:10,1')->name('admin.student-api.test');
});
