<?php

use App\Http\Controllers\Api\RetentionDailyController;
use App\Http\Controllers\CallRecordingUploadController;
use App\Http\Middleware\AuthenticateApiKey;
use Illuminate\Support\Facades\Route;

// From each CRA's phone (MacroDroid), signed with the CRA's upload token from Settings → Call Recordings.
Route::post('/call-recordings', CallRecordingUploadController::class)->middleware('throttle:60,1')->name('api.call-recordings.store');

// For other departments' reports, with a key from Settings → API Keys.
Route::prefix('external/v1')->name('api.external.')->middleware([AuthenticateApiKey::class, 'throttle:60,1'])->group(function () {
    Route::get('/retention/daily', RetentionDailyController::class)->name('retention.daily');
});
