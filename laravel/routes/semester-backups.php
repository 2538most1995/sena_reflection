<?php

use App\Http\Controllers\SemesterBackupController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('admin/semester-backups')->name('semester-backups.')->group(function (): void {
    Route::get('/', [SemesterBackupController::class, 'index'])->name('index');
    Route::post('/', [SemesterBackupController::class, 'store'])->middleware('throttle:6,1')->name('store');
    Route::post('/import', [SemesterBackupController::class, 'import'])->middleware('throttle:6,1')->name('import');
    Route::get('/{backup}', [SemesterBackupController::class, 'show'])->whereUuid('backup')->name('show');
    Route::get('/{backup}/download', [SemesterBackupController::class, 'download'])->whereUuid('backup')->name('download');
    Route::post('/{backup}/archive', [SemesterBackupController::class, 'archive'])->whereUuid('backup')->name('archive');
    Route::post('/{backup}/restore', [SemesterBackupController::class, 'restore'])->whereUuid('backup')->name('restore');
});
