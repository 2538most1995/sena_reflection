<?php

use App\Http\Controllers\BrowserExportController;
use App\Http\Controllers\ExportJobController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    Route::get('/exports', [ExportJobController::class, 'index'])->name('exports.index');
    Route::post('/exports', [ExportJobController::class, 'store'])->middleware('throttle:export-requests')->name('exports.store');
    Route::get('/exports/browser-print', [BrowserExportController::class, 'index'])->middleware('throttle:document-previews')->name('exports.browser-print');
    Route::get('/exports/revisions/{revision}/browser-print', [BrowserExportController::class, 'revision'])->middleware('signed')->name('exports.browser-print.revision');
    Route::get('/exports/{export}/browser-print', [BrowserExportController::class, 'export'])->middleware('throttle:document-previews')->name('exports.browser-print.existing');
    Route::get('/exports/{export}', [ExportJobController::class, 'show'])->name('exports.show');
    Route::post('/exports/{export}/retry', [ExportJobController::class, 'retry'])->middleware('throttle:export-requests')->name('exports.retry');
    Route::get('/exports/{export}/download', [ExportJobController::class, 'download'])->name('exports.download');
});
