<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AttendanceAnalysisController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\LegacyApiController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RecordController;
use App\Http\Controllers\RecordCreateController;
use App\Http\Controllers\RecordDocumentController;
use App\Http\Controllers\StudentDirectoryController;
use App\Http\Controllers\WeeklySubmissionController;
use Illuminate\Support\Facades\Route;

require __DIR__.'/exports.php';
require __DIR__.'/workspace.php';
require __DIR__.'/draft-documents.php';
require __DIR__.'/documents.php';

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:login');
});

Route::middleware('auth')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::post('/weekly-submissions', [WeeklySubmissionController::class, 'store'])->name('weekly-submissions.store');
    Route::get('/records/create', [RecordCreateController::class, 'create'])->name('records.create');
    Route::get('/record-options/students', [RecordCreateController::class, 'students'])->name('record-options.students');
    Route::get('/records/{record}', [RecordController::class, 'show'])->name('records.show');
    Route::get('/records/{record}/document', [RecordDocumentController::class, 'edit'])->name('records.document.edit');
    Route::post('/records/{record}/document/preview', [RecordDocumentController::class, 'preview'])->name('records.document.preview');
    Route::post('/records/{record}/document/browser-preview', [RecordDocumentController::class, 'browserPreview'])->middleware('throttle:document-previews')->name('records.document.browser-preview');
    Route::post('/records/{record}/document/pdf-preview', [RecordDocumentController::class, 'pdfPreview'])->middleware('throttle:document-previews')->name('records.document.pdf-preview');
    Route::put('/records/{record}/document', [RecordDocumentController::class, 'update'])->name('records.document.update');
    Route::delete('/records/{record}/document', [RecordDocumentController::class, 'destroy'])->name('records.document.destroy');
    Route::get('/records/{record}/print', [RecordController::class, 'print'])->middleware('throttle:exports')->name('records.print');
    Route::get('/records/{record}/browser-print', [RecordController::class, 'browserPrint'])->middleware('throttle:document-previews')->name('records.browser-print');
    Route::get('/records/{record}/images/0', [RecordController::class, 'legacyImageGallery'])->name('records.images.legacy-gallery');
    Route::get('/records/{record}/images/{image}', [RecordController::class, 'image'])->name('records.images.show');
    Route::get('/records/{record}/image-files/{asset}', [RecordController::class, 'imageFile'])->where('asset', '[a-f0-9]{64}')->name('records.image-files.show');
    Route::get('/records/{record}/edit', [RecordController::class, 'edit'])->name('records.edit');
    Route::put('/records/{record}', [RecordController::class, 'update'])->name('records.update');
    Route::delete('/records/{record}', [RecordController::class, 'destroy'])->name('records.destroy');
    Route::get('/downloads/records', ExportController::class)->middleware('throttle:exports')->name('records.download');
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::get('/students', StudentDirectoryController::class)->name('students');
    Route::get('/attendance-analysis', AttendanceAnalysisController::class)->name('attendance.analysis');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('/admin', [AdminController::class, 'index'])->name('admin');
    Route::post('/admin/users', [AdminController::class, 'storeUser'])->name('admin.users.store');
    Route::put('/admin/users/{user}', [AdminController::class, 'updateUser'])->name('admin.users.update');
    Route::delete('/admin/users/{user}', [AdminController::class, 'destroyUser'])->name('admin.users.destroy');
    Route::post('/admin/tambons', [AdminController::class, 'storeTambon'])->name('admin.tambons.store');
    Route::put('/admin/tambons/{tambon}', [AdminController::class, 'updateTambon'])->name('admin.tambons.update');
    Route::delete('/admin/tambons/{tambon}', [AdminController::class, 'destroyTambon'])->name('admin.tambons.destroy');
    Route::put('/admin/settings', [AdminController::class, 'settings'])->name('admin.settings');
    Route::post('/admin/pdf-preview', [AdminController::class, 'preview'])->name('admin.pdf-preview');
});

// Compatibility endpoints used by the original single-page application.
Route::match(['get', 'post'], '/api/auth.php', [LegacyApiController::class, 'auth']);
Route::match(['get', 'post', 'put', 'delete'], '/api/records.php', [LegacyApiController::class, 'records']);
Route::get('/api/dashboard.php', [LegacyApiController::class, 'dashboard']);
Route::get('/api/attendance_report.php', [LegacyApiController::class, 'attendance']);
Route::match(['get', 'post', 'put'], '/api/settings.php', [LegacyApiController::class, 'settings']);
Route::match(['get', 'post', 'put', 'delete'], '/api/tambons.php', [LegacyApiController::class, 'tambons']);
Route::match(['get', 'post', 'put', 'delete'], '/api/users.php', [LegacyApiController::class, 'users']);
Route::match(['get', 'post'], '/api/profile.php', [LegacyApiController::class, 'profile']);
Route::post('/api/upload_image.php', [LegacyApiController::class, 'upload']);
Route::match(['get', 'post'], '/api/logo.php', [LegacyApiController::class, 'logo']);
Route::get('/api/student_api.php', [LegacyApiController::class, 'studentApi']);
Route::get('/api/download_pdf.php', [LegacyApiController::class, 'downloadPdf'])->middleware('throttle:exports');
Route::get('/api/export_doc.php', [LegacyApiController::class, 'exportDoc'])->middleware('throttle:exports');
Route::match(['get', 'post'], '/api/export_bundle.php', [LegacyApiController::class, 'exportBundle'])->middleware('throttle:exports');
Route::get('/api/code_health.php', [LegacyApiController::class, 'health']);
Route::get('/api/server_check.php', [LegacyApiController::class, 'health']);
Route::get('/print.php', [LegacyApiController::class, 'printHtml'])->name('legacy.print');
