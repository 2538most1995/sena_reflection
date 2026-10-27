<?php

use App\Http\Controllers\SemesterBackupController;
use App\Http\Controllers\SemesterBackupUploadController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('admin/semester-backups')->name('semester-backups.')->group(function (): void {
    Route::get('/', [SemesterBackupController::class, 'index'])->name('index');
    Route::post('/', [SemesterBackupController::class, 'store'])->middleware('throttle:6,1')->name('store');
    Route::post('/import', [SemesterBackupController::class, 'import'])->middleware('throttle:6,1')->name('import');
    Route::get('/preflight', [SemesterBackupController::class, 'preflight'])->name('preflight');
    Route::post('/terms/preview', [SemesterBackupController::class, 'repairPreview'])->name('terms.preview');
    Route::post('/terms/repair', [SemesterBackupController::class, 'repair'])->middleware('throttle:6,1')->name('terms.repair');
    Route::get('/uploads', [SemesterBackupUploadController::class, 'index'])->name('uploads.index');
    Route::post('/uploads', [SemesterBackupUploadController::class, 'start'])->middleware('throttle:12,1')->name('uploads.start');
    Route::post('/uploads/{upload}/finish', [SemesterBackupUploadController::class, 'finish'])->whereUuid('upload')->name('uploads.finish');
    Route::post('/uploads/{upload}/{part}', [SemesterBackupUploadController::class, 'part'])->whereUuid('upload')->whereNumber('part')->middleware('throttle:300,1')->name('uploads.part');
    Route::delete('/uploads/{upload}', [SemesterBackupUploadController::class, 'cancel'])->whereUuid('upload')->name('uploads.cancel');
    Route::get('/{backup}', [SemesterBackupController::class, 'show'])->whereUuid('backup')->name('show');
    Route::get('/{backup}/download', [SemesterBackupController::class, 'download'])->whereUuid('backup')->name('download');
    Route::post('/{backup}/archive', [SemesterBackupController::class, 'archive'])->whereUuid('backup')->name('archive');
    Route::post('/{backup}/closeout', [SemesterBackupController::class, 'closeout'])->whereUuid('backup')->name('closeout');
    Route::post('/{backup}/restore', [SemesterBackupController::class, 'restore'])->whereUuid('backup')->name('restore');
    Route::post('/{backup}/release', [SemesterBackupController::class, 'release'])->whereUuid('backup')->name('release');
    Route::post('/{backup}/offload', [SemesterBackupController::class, 'offload'])->whereUuid('backup')->name('offload');
});
