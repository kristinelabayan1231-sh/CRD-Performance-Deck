<?php

use App\Http\Controllers\Admin\AllowedEmailController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\PerformanceDeckController;
use Illuminate\Support\Facades\Route;

Route::view('/login', 'auth.login')->name('login');
Route::post('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->name('auth.google.redirect');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');
Route::post('/logout', [SessionController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/', [PerformanceDeckController::class, 'index'])->name('deck.index');

    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/emails', [AllowedEmailController::class, 'index'])->name('emails.index');
        Route::post('/emails', [AllowedEmailController::class, 'store'])->name('emails.store');
        Route::delete('/emails/{allowedEmail}', [AllowedEmailController::class, 'destroy'])->name('emails.destroy');
    });
});
