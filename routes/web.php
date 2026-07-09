<?php

use App\Http\Controllers\Admin\AllowedEmailController;
use App\Http\Controllers\Admin\CraController;
use App\Http\Controllers\Admin\FacebookPageController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\PerformanceDeckController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PancakeDataController;

Route::view('/login', 'auth.login')->name('login');
Route::post('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->name('auth.google.redirect');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');
Route::post('/logout', [SessionController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/', [PerformanceDeckController::class, 'index'])->name('deck.index');
    Route::get('/pancake-data', [PancakeDataController::class, 'index'])->name('pancake.index');

    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/emails', [AllowedEmailController::class, 'index'])->name('emails.index');
        Route::post('/emails', [AllowedEmailController::class, 'store'])->name('emails.store');
        Route::delete('/emails/{allowedEmail}', [AllowedEmailController::class, 'destroy'])->name('emails.destroy');

        Route::get('/facebook-pages', [FacebookPageController::class, 'index'])->name('facebook-pages.index');
        Route::post('/facebook-pages', [FacebookPageController::class, 'store'])->name('facebook-pages.store');
        Route::delete('/facebook-pages/{facebookPage}', [FacebookPageController::class, 'destroy'])->name('facebook-pages.destroy');

        Route::get('/cras', [CraController::class, 'index'])->name('cras.index');
        Route::post('/cras', [CraController::class, 'store'])->name('cras.store');
        Route::delete('/cras/{cra}', [CraController::class, 'destroy'])->name('cras.destroy');
        Route::post('/cra-assignments', [CraController::class, 'storeAssignment'])->name('cra-assignments.store');
        Route::delete('/cra-assignments/{craAssignment}', [CraController::class, 'destroyAssignment'])->name('cra-assignments.destroy');
    });
});