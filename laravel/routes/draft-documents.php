<?php

use App\Http\Controllers\DraftDocumentController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('teaching-drafts/{teachingDraft}/document/{levelCode}')
    ->where(['levelCode' => '[123]'])->name('teaching-drafts.document.')->group(function (): void {
        Route::get('/', [DraftDocumentController::class, 'edit'])->name('edit');
        Route::post('/preview', [DraftDocumentController::class, 'preview'])->name('preview');
        Route::post('/browser-preview', [DraftDocumentController::class, 'browserPreview'])->middleware('throttle:document-previews')->name('browser-preview');
        Route::post('/pdf-preview', [DraftDocumentController::class, 'pdfPreview'])->middleware('throttle:document-previews')->name('pdf-preview');
        Route::put('/', [DraftDocumentController::class, 'update'])->name('update');
    });
