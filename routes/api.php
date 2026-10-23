<?php

use App\Http\Controllers\CallRecordingUploadController;
use Illuminate\Support\Facades\Route;

// From each CRA's phone (MacroDroid), signed with the CRA's upload token from Settings → Call Recordings.
Route::post('/call-recordings', CallRecordingUploadController::class)->middleware('throttle:60,1')->name('api.call-recordings.store');
