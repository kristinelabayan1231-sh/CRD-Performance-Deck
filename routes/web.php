<?php

use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\BacklogTransferController;
use App\Http\Controllers\ConnectionCheckController;
use App\Http\Controllers\ConversionBreakdownController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PancakePageController;
use App\Http\Controllers\ProductConsumptionController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SalesGoalController;
use App\Http\Controllers\SegmentationController;
use App\Http\Controllers\SegmentationProductivityController;
use App\Http\Controllers\UserAccessController;
use App\Http\Controllers\WeeklySegmentationController;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [GoogleController::class, 'showLogin'])->name('login');
    Route::get('/auth/google/redirect', [GoogleController::class, 'redirect'])->name('auth.google.redirect');
    Route::get('/auth/google/callback', [GoogleController::class, 'callback'])->name('auth.google.callback');
});

Route::middleware(['auth', EnsureUserIsActive::class])->group(function () {
    Route::redirect('/', '/dashboard');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::post('/logout', [GoogleController::class, 'logout'])->name('logout');

    Route::prefix('user-access')->group(function () {
        Route::middleware('can:roles.manage')->prefix('roles')->name('roles.')->group(function () {
            Route::get('/', [RoleController::class, 'index'])->name('index');
            Route::post('/', [RoleController::class, 'store'])->name('store');
            Route::patch('/{role}', [RoleController::class, 'update'])->name('update');
            Route::delete('/{role}', [RoleController::class, 'destroy'])->name('destroy');
        });

        Route::name('user-access.')->group(function () {
            Route::get('/', [UserAccessController::class, 'index'])->middleware('can:user_access.view')->name('index');

            Route::middleware('can:user_access.manage')->group(function () {
                Route::post('/', [UserAccessController::class, 'store'])->name('store');
                Route::patch('/{user}', [UserAccessController::class, 'update'])->name('update');
                Route::patch('/{user}/display-name', [UserAccessController::class, 'updateDisplayName'])->name('display-name');
                Route::patch('/{user}/pancake-account', [UserAccessController::class, 'updatePancakeAccount'])->name('pancake-account');
                Route::delete('/{user}', [UserAccessController::class, 'destroy'])->name('destroy');
            });
        });
    });

    Route::prefix('segmentation')->name('segmentation.')->middleware('can:segmentation.view')->group(function () {
        Route::get('/', [SegmentationController::class, 'index'])->name('index');
        Route::get('/summary', [SegmentationController::class, 'summary'])->name('summary');
        Route::get('/weekly', [WeeklySegmentationController::class, 'index'])->name('weekly');
        Route::post('/backlog/transfer', [BacklogTransferController::class, 'store'])->middleware('can:segmentation.transfer')->name('transfer');
        Route::patch('/leads/{lead}', [SegmentationController::class, 'update'])->name('update');
        Route::post('/sync', [SegmentationController::class, 'sync'])->middleware('can:segmentation.manage')->name('sync');
    });

    Route::prefix('segmentation-productivity')->name('productivity.')->middleware('can:productivity.view')->group(function () {
        Route::get('/', [SegmentationProductivityController::class, 'index'])->name('index');
        Route::post('/sync', [SegmentationProductivityController::class, 'sync'])->middleware('can:productivity.view_all')->name('sync');
    });

    Route::prefix('conversion-breakdown')->name('conversion.')->middleware('can:conversion.view')->group(function () {
        Route::get('/', [ConversionBreakdownController::class, 'index'])->name('index');
        Route::post('/sync', [ConversionBreakdownController::class, 'sync'])->middleware('can:conversion.view_all')->name('sync');
    });

    Route::prefix('settings')->name('settings.')->group(function () {
        // The first Settings tab this user may open.
        Route::get('/', fn () => redirect()->route(
            match (true) {
                request()->user()->can('product_consumption.view') => 'settings.product-consumption.index',
                request()->user()->can('pancake_pages.manage') => 'settings.pancake-pages.index',
                request()->user()->can('sales_goals.manage') => 'settings.sales-goals.index',
                default => 'settings.connections.index',
            }
        ))->name('index');

        Route::prefix('pancake-pages')->name('pancake-pages.')->middleware('can:pancake_pages.manage')->group(function () {
            Route::get('/', [PancakePageController::class, 'index'])->name('index');
            Route::post('/', [PancakePageController::class, 'store'])->name('store');
            Route::patch('/{page}', [PancakePageController::class, 'update'])->name('update');
            Route::delete('/{page}', [PancakePageController::class, 'destroy'])->name('destroy');
            Route::post('/{page}/test', [PancakePageController::class, 'test'])->name('test');
        });

        Route::prefix('sales-goals')->name('sales-goals.')->middleware('can:sales_goals.manage')->group(function () {
            Route::get('/', [SalesGoalController::class, 'index'])->name('index');
            Route::put('/', [SalesGoalController::class, 'update'])->name('update');
        });

        Route::prefix('connections')->name('connections.')->middleware('can:connections.check')->group(function () {
            Route::get('/', [ConnectionCheckController::class, 'index'])->name('index');
            Route::post('/', [ConnectionCheckController::class, 'run'])->name('run');
        });

        Route::prefix('product-consumption')->name('product-consumption.')->group(function () {
            Route::get('/', [ProductConsumptionController::class, 'index'])->middleware('can:product_consumption.view')->name('index');

            Route::middleware('can:product_consumption.manage')->group(function () {
                Route::post('/', [ProductConsumptionController::class, 'store'])->name('store');
                Route::patch('/{product}', [ProductConsumptionController::class, 'update'])->name('update');
                Route::delete('/{product}', [ProductConsumptionController::class, 'destroy'])->name('destroy');
            });
        });
    });
});
