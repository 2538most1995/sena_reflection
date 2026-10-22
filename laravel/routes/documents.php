<?php

use App\Http\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    Route::get('/documents', [DocumentController::class, 'indexPage'])->name('documents.index');
    Route::get('/documents/{document}/edit', [DocumentController::class, 'editPage'])->whereUuid('document')->name('documents.edit');
    Route::prefix('api/documents')->name('documents.api.')->group(function (): void {
        Route::get('/', [DocumentController::class, 'index'])->name('index');
        Route::post('/', [DocumentController::class, 'store'])->name('store');
        Route::post('/from-record/{record}', [DocumentController::class, 'fromRecord'])->name('from-record');
        Route::get('/{document}', [DocumentController::class, 'show'])->whereUuid('document')->name('show');
        Route::put('/{document}', [DocumentController::class, 'update'])->whereUuid('document')->name('update');
        Route::delete('/{document}', [DocumentController::class, 'destroy'])->whereUuid('document')->name('destroy');
        Route::post('/{document}/duplicate', [DocumentController::class, 'duplicate'])->whereUuid('document')->name('duplicate');
        Route::get('/{document}/assets', [DocumentController::class, 'assets'])->whereUuid('document')->name('assets.index');
        Route::post('/{document}/assets', [DocumentController::class, 'upload'])->whereUuid('document')->middleware('throttle:60,1')->name('assets.store');
        Route::get('/{document}/versions', [DocumentController::class, 'versions'])->whereUuid('document')->name('versions.index');
        Route::post('/{document}/versions/{version}/restore', [DocumentController::class, 'restore'])->whereUuid(['document', 'version'])->name('versions.restore');
    });
    Route::get('/api/documents/{document}/assets/{asset}', [DocumentController::class, 'asset'])->whereUuid(['document', 'asset'])->name('documents.assets.show');
});
