<?php

use App\Http\Controllers\ExportJobController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    Route::get('/exports', [ExportJobController::class, 'index'])->name('exports.index');
    Route::post('/exports', [ExportJobController::class, 'store'])->middleware('throttle:exports')->name('exports.store');
    Route::get('/exports/{export}', [ExportJobController::class, 'show'])->name('exports.show');
    Route::post('/exports/{export}/retry', [ExportJobController::class, 'retry'])->middleware('throttle:exports')->name('exports.retry');
    Route::get('/exports/{export}/download', [ExportJobController::class, 'download'])->name('exports.download');
});
