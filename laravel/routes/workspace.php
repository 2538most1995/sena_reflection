<?php

use App\Http\Controllers\DraftImageController;
use App\Http\Controllers\RecordCreateController;
use App\Http\Controllers\TeachingDraftController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    Route::get('/record-options/workspace', [RecordCreateController::class, 'options'])->name('record-options.workspace');

    Route::get('/teaching-drafts', [TeachingDraftController::class, 'index'])->name('teaching-drafts.index');
    Route::post('/teaching-drafts', [TeachingDraftController::class, 'store'])->name('teaching-drafts.store');
    Route::get('/teaching-drafts/{teachingDraft}', [TeachingDraftController::class, 'show'])->name('teaching-drafts.show');
    Route::patch('/teaching-drafts/{teachingDraft}', [TeachingDraftController::class, 'update'])->name('teaching-drafts.update');
    Route::post('/teaching-drafts/{teachingDraft}/preview', [TeachingDraftController::class, 'preview'])->middleware('throttle:document-previews')->name('teaching-drafts.preview');
    Route::post('/teaching-drafts/{teachingDraft}/finalize', [TeachingDraftController::class, 'finalize'])->name('teaching-drafts.finalize');

    Route::post('/teaching-drafts/{teachingDraft}/images', [DraftImageController::class, 'store'])->middleware('throttle:30,1')->name('teaching-drafts.images.store');
    Route::get('/teaching-drafts/{teachingDraft}/images/{draftImage}', [DraftImageController::class, 'show'])->name('teaching-drafts.images.show');
    Route::delete('/teaching-drafts/{teachingDraft}/images/{draftImage}', [DraftImageController::class, 'destroy'])->name('teaching-drafts.images.destroy');
    Route::patch('/teaching-drafts/{teachingDraft}/images/reorder', [DraftImageController::class, 'reorder'])->name('teaching-drafts.images.reorder');
});
