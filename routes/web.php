<?php

use App\Http\Controllers\Admin\AllowedEmailController;
use App\Http\Controllers\Admin\CraCallStatController as AdminCraCallStatController;
use App\Http\Controllers\Admin\CraController;
use App\Http\Controllers\Admin\FacebookPageController;
use App\Http\Controllers\Admin\PcController;
use App\Http\Controllers\Admin\PosCredentialController;
use App\Http\Controllers\Admin\WeeklyConversationTagController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\CraCallStatController;
use App\Http\Controllers\CraCohortController;
use App\Http\Controllers\CraDayFinalizationController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\PerformanceDeckController;
use App\Http\Controllers\SegmentationReportController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PancakeDataController;

Route::view('/login', 'auth.login')->name('login');
Route::post('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->name('auth.google.redirect');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');
Route::post('/logout', [SessionController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/', [PerformanceDeckController::class, 'index'])->name('deck.index');
    Route::get('/pancake-data', [PancakeDataController::class, 'index'])->name('pancake.index');
    Route::post('/pancake-data/entry', [PancakeDataController::class, 'updateEntry'])->name('pancake.entry');
    Route::post('/my-cohorts', [CraCohortController::class, 'store'])->name('cra.cohorts.store');
    Route::post('/my-call-stats', [CraCallStatController::class, 'store'])->name('cra.call-stats.store');
    Route::post('/cra-day-finalizations', [CraDayFinalizationController::class, 'store'])->name('cra-day-finalizations.store');
    Route::delete('/cra-day-finalizations/{craDayFinalization}', [CraDayFinalizationController::class, 'destroy'])->name('cra-day-finalizations.destroy');
    Route::post('/customers/build-dashboard', [CustomerController::class, 'buildDashboard'])->name('customers.build-dashboard');

    Route::get('/segmentation-report', [SegmentationReportController::class, 'index'])->name('segmentation.index');

    Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('/customers/{customerId}', [CustomerController::class, 'show'])->name('customers.show');

    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/emails', [AllowedEmailController::class, 'index'])->name('emails.index');
        Route::post('/emails', [AllowedEmailController::class, 'store'])->name('emails.store');
        Route::delete('/emails/{allowedEmail}', [AllowedEmailController::class, 'destroy'])->name('emails.destroy');

        Route::get('/facebook-pages', [FacebookPageController::class, 'index'])->name('facebook-pages.index');
        Route::post('/facebook-pages', [FacebookPageController::class, 'store'])->name('facebook-pages.store');
        Route::delete('/facebook-pages/{facebookPage}', [FacebookPageController::class, 'destroy'])->name('facebook-pages.destroy');

        Route::get('/pcs', [PcController::class, 'index'])->name('pcs.index');
        Route::post('/pcs', [PcController::class, 'store'])->name('pcs.store');
        Route::post('/pcs/{pc}/assign-page', [PcController::class, 'assignPage'])->name('pcs.assign-page');
        Route::get('/pcs/page-accounts/{facebookPage}', [PcController::class, 'accounts'])->name('pcs.page-accounts');
        Route::delete('/pcs/{pc}', [PcController::class, 'destroy'])->name('pcs.destroy');

        Route::get('/cras', [CraController::class, 'index'])->name('cras.index');
        Route::post('/cras', [CraController::class, 'store'])->name('cras.store');
        Route::delete('/cras/{cra}', [CraController::class, 'destroy'])->name('cras.destroy');
        Route::post('/cra-pc-assignments', [CraController::class, 'storeWeeklyCohorts'])->name('cra-pc-assignments.store');
        Route::delete('/cra-pc-assignments/{craPcAssignment}', [CraController::class, 'destroyAssignment'])->name('cra-pc-assignments.destroy');

        Route::post('/weekly-conversation-tags', [WeeklyConversationTagController::class, 'store'])->name('weekly-conversation-tags.store');
        Route::delete('/weekly-conversation-tags/{weeklyConversationTag}', [WeeklyConversationTagController::class, 'destroy'])->name('weekly-conversation-tags.destroy');

        Route::post('/pos-credentials', [PosCredentialController::class, 'store'])->name('pos-credentials.store');
        Route::delete('/pos-credentials/{posCredential}', [PosCredentialController::class, 'destroy'])->name('pos-credentials.destroy');

        Route::post('/cra-call-stats', [AdminCraCallStatController::class, 'store'])->name('cra-call-stats.store');
        Route::delete('/cra-call-stats/{craCallStat}', [AdminCraCallStatController::class, 'destroy'])->name('cra-call-stats.destroy');
    });
});