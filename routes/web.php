<?php

use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\BacklogTransferController;
use App\Http\Controllers\CancelledOrderController;
use App\Http\Controllers\ConnectionCheckController;
use App\Http\Controllers\ConversionBreakdownController;
use App\Http\Controllers\CustomerChurnController;
use App\Http\Controllers\CustomerDatabaseController;
use App\Http\Controllers\CvrTrackerController;
use App\Http\Controllers\DailyIncomeStatementController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DashboardLiveController;
use App\Http\Controllers\HighValueCustomerController;
use App\Http\Controllers\MonthlyCostController;
use App\Http\Controllers\MonthlyMniController;
use App\Http\Controllers\OnDutyController;
use App\Http\Controllers\OrderDatabaseController;
use App\Http\Controllers\OrderIssueRecheckController;
use App\Http\Controllers\PancakeAccountController;
use App\Http\Controllers\PancakePageController;
use App\Http\Controllers\PcReportController;
use App\Http\Controllers\ProductConsumptionController;
use App\Http\Controllers\ProductPerformanceController;
use App\Http\Controllers\ProductQtyDisplayController;
use App\Http\Controllers\RepeatPurchaseController;
use App\Http\Controllers\ReportSummaryController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SalesGoalController;
use App\Http\Controllers\SegmentationController;
use App\Http\Controllers\SegmentationOptionController;
use App\Http\Controllers\SegmentationProductivityController;
use App\Http\Controllers\SegmentationSummaryController;
use App\Http\Controllers\UserAccessController;
use App\Http\Controllers\WeeklySegmentationController;
use App\Http\Controllers\WorkingDateController;
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
    Route::get('/dashboard/live', DashboardLiveController::class)->name('dashboard.live');
    Route::post('/logout', [GoogleController::class, 'logout'])->name('logout');
    Route::post('/order-issues/recheck', OrderIssueRecheckController::class)->middleware('throttle:6,1')->name('order-issues.recheck');

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
        Route::get('/overview', [SegmentationSummaryController::class, 'index'])->name('overview');
        Route::get('/repeat-purchases', [RepeatPurchaseController::class, 'index'])->name('repeat-purchases');
        Route::post('/backlog/transfer', [BacklogTransferController::class, 'store'])->middleware('can:segmentation.transfer')->name('transfer');
        Route::patch('/leads/{lead}', [SegmentationController::class, 'update'])->name('update');
        Route::get('/leads/{lead}/orders', [SegmentationController::class, 'orders'])->name('orders');
        Route::post('/sync', [SegmentationController::class, 'sync'])->middleware('can:segmentation.manage')->name('sync');
        Route::post('/on-duty', [OnDutyController::class, 'store'])->name('on-duty');
    });

    Route::prefix('segmentation-productivity')->name('productivity.')->middleware('can:productivity.view')->group(function () {
        Route::get('/', [SegmentationProductivityController::class, 'index'])->name('index');
        Route::post('/sync', [SegmentationProductivityController::class, 'sync'])->middleware('can:productivity.view_all')->name('sync');
    });

    Route::prefix('conversion-breakdown')->name('conversion.')->middleware('can:conversion.view')->group(function () {
        Route::get('/', [ConversionBreakdownController::class, 'index'])->name('index');
        Route::get('/orders', [ConversionBreakdownController::class, 'orders'])->name('orders');
        Route::get('/cras/{cra}/orders', [ConversionBreakdownController::class, 'craOrders'])->name('cra-orders');
        Route::post('/sync', [ConversionBreakdownController::class, 'sync'])->middleware('can:conversion.view_all')->name('sync');
    });

    // Opened from the dashboard's Cancelled / Waiting tile only (no menu link).
    Route::get('/cancelled-orders', [CancelledOrderController::class, 'index'])->middleware('can:conversion.view')->name('cancelled-orders.index');

    Route::prefix('order-database')->name('order-database.')->middleware('can:order_database.view')->group(function () {
        Route::redirect('/', '/order-database/cra-performance')->name('index');
        Route::get('/cra-performance', [OrderDatabaseController::class, 'craPerformance'])->name('cra-performance');
        Route::get('/product-report', [OrderDatabaseController::class, 'productReport'])->name('product-report');
    });

    Route::get('/report-summary', [ReportSummaryController::class, 'index'])->middleware('can:report_summary.view')->name('report-summary.index');
    Route::get('/product-performance', [ProductPerformanceController::class, 'index'])->middleware('can:product_performance.view')->name('product-performance.index');

    Route::prefix('pc-report')->name('pc-report.')->middleware('can:pc_report.view')->group(function () {
        Route::get('/', [PcReportController::class, 'index'])->name('index');
        Route::put('/rows', [PcReportController::class, 'update'])->name('rows.update');
        Route::post('/split', [PcReportController::class, 'split'])->middleware('can:pc_report.manage')->name('split');
        Route::delete('/split', [PcReportController::class, 'merge'])->middleware('can:pc_report.manage')->name('merge');
        Route::put('/entries', [PcReportController::class, 'entry'])->name('entries.update');
    });

    Route::prefix('monthly-mni')->name('mni.')->middleware('can:mni.view')->group(function () {
        Route::get('/', [MonthlyMniController::class, 'index'])->name('index');
        Route::put('/', [MonthlyMniController::class, 'update'])->middleware('can:mni.manage')->name('update');
    });

    Route::get('/daily-is', [DailyIncomeStatementController::class, 'index'])->middleware('can:daily_is.view')->name('daily-is.index');
    Route::put('/daily-is/returns', [DailyIncomeStatementController::class, 'updateReturns'])->middleware('can:daily_is.view_all')->name('daily-is.returns.update');

    Route::prefix('cvr-tracker')->name('cvr-tracker.')->middleware('can:cvr_tracker.view')->group(function () {
        Route::get('/', [CvrTrackerController::class, 'index'])->name('index');
        Route::post('/refresh', [CvrTrackerController::class, 'refresh'])->middleware('throttle:6,1')->name('refresh');
        Route::put('/orders/{orderId}', [CvrTrackerController::class, 'updateOrder'])->name('orders.update');
        Route::put('/days', [CvrTrackerController::class, 'updateDay'])->name('days.update');
    });

    // High AOV CVR & VIP: CRAs see it without the rest of the Customer Database.
    Route::get('/customers/high-value', [HighValueCustomerController::class, 'index'])->middleware('can:customers.high_value.view')->name('customers.high-value');
    Route::get('/customers/high-value/{phoneKey}', [HighValueCustomerController::class, 'show'])->where('phoneKey', '[0-9]+')
        ->middleware('can:customers.high_value.view')->name('customers.high-value.show');
    Route::patch('/customers/high-value/{phoneKey}', [HighValueCustomerController::class, 'update'])->where('phoneKey', '[0-9]+')
        ->middleware('can:customers.high_value.edit')->name('customers.high-value.update');

    Route::prefix('customers')->name('customers.')->middleware('can:customers.view')->group(function () {
        Route::get('/', [CustomerDatabaseController::class, 'index'])->name('index');
        Route::get('/churn', [CustomerChurnController::class, 'index'])->name('churn');
        Route::get('/churn/{phoneKey}', [CustomerChurnController::class, 'history'])->where('phoneKey', '[0-9]+')->name('churn.history');
        Route::get('/{phoneKey}', [CustomerDatabaseController::class, 'show'])->where('phoneKey', '[0-9]+')->name('show');
        Route::post('/{phoneKey}/merge', [CustomerDatabaseController::class, 'merge'])->where('phoneKey', '[0-9]+')->middleware('can:customers.merge')->name('merge');
        Route::delete('/{phoneKey}/merge', [CustomerDatabaseController::class, 'separate'])->where('phoneKey', '[0-9]+')->middleware('can:customers.merge')->name('separate');
    });

    Route::prefix('crd-mc')->name('monthly-cost.')->middleware('can:monthly_cost.manage')->group(function () {
        Route::get('/', [MonthlyCostController::class, 'index'])->name('index');
        Route::post('/', [MonthlyCostController::class, 'store'])->name('store');
        Route::put('/settings', [MonthlyCostController::class, 'settings'])->name('settings');
        Route::patch('/{row}', [MonthlyCostController::class, 'update'])->name('update');
        Route::delete('/{row}', [MonthlyCostController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('settings')->name('settings.')->group(function () {
        // The first Settings tab this user may open.
        Route::get('/', fn () => redirect()->route(
            match (true) {
                request()->user()->can('product_consumption.view') => 'settings.product-consumption.index',
                request()->user()->can('pancake_pages.manage') => 'settings.pancake-pages.index',
                request()->user()->can('pancake_accounts.manage') => 'settings.pancake-accounts.index',
                request()->user()->can('sales_goals.manage') => 'settings.sales-goals.index',
                request()->user()->can('segmentation_options.manage') => 'settings.segmentation-options.index',
                default => 'settings.connections.index',
            }
        ))->name('index');

        Route::prefix('pancake-pages')->name('pancake-pages.')->middleware('can:pancake_pages.manage')->group(function () {
            Route::get('/', [PancakePageController::class, 'index'])->name('index');
            Route::post('/', [PancakePageController::class, 'store'])->name('store');
            Route::put('/groups', [PancakePageController::class, 'saveGroups'])->name('groups');
            Route::patch('/{page}', [PancakePageController::class, 'update'])->name('update');
            Route::delete('/{page}', [PancakePageController::class, 'destroy'])->name('destroy');
            Route::post('/{page}/test', [PancakePageController::class, 'test'])->name('test');
        });

        Route::prefix('pancake-accounts')->name('pancake-accounts.')->middleware('can:pancake_accounts.manage')->group(function () {
            Route::get('/', [PancakeAccountController::class, 'index'])->name('index');
            Route::put('/', [PancakeAccountController::class, 'update'])->name('update');
        });

        Route::prefix('sales-goals')->name('sales-goals.')->middleware('can:sales_goals.manage')->group(function () {
            Route::get('/', [SalesGoalController::class, 'index'])->name('index');
            Route::put('/', [SalesGoalController::class, 'update'])->name('update');
        });

        Route::prefix('working-date')->name('working-date.')->middleware('can:sales_goals.manage')->group(function () {
            Route::get('/', [WorkingDateController::class, 'index'])->name('index');
            Route::put('/', [WorkingDateController::class, 'update'])->name('update');
        });

        Route::prefix('segmentation-tracker')->name('segmentation-options.')->middleware('can:segmentation_options.manage')->group(function () {
            Route::get('/', [SegmentationOptionController::class, 'index'])->name('index');
            Route::put('/{list}', [SegmentationOptionController::class, 'update'])->where('list', '[a-z_]+')->name('update');
        });

        Route::prefix('product-qty')->name('product-qty.')->middleware('can:product_qty.manage')->group(function () {
            Route::get('/', [ProductQtyDisplayController::class, 'index'])->name('index');
            Route::patch('/{user}', [ProductQtyDisplayController::class, 'update'])->name('update');
        });

        Route::prefix('connections')->name('connections.')->middleware('can:connections.check')->group(function () {
            Route::get('/', [ConnectionCheckController::class, 'index'])->name('index');
            Route::post('/', [ConnectionCheckController::class, 'run'])->name('run');
        });

        Route::prefix('product-consumption')->name('product-consumption.')->group(function () {
            Route::get('/', [ProductConsumptionController::class, 'index'])->middleware('can:product_consumption.view')->name('index');
            Route::patch('/{product}/srp', [ProductConsumptionController::class, 'updateSrp'])->middleware('can:product_consumption.srp')->name('srp');

            Route::middleware('can:product_consumption.manage')->group(function () {
                Route::post('/', [ProductConsumptionController::class, 'store'])->name('store');
                Route::patch('/{product}', [ProductConsumptionController::class, 'update'])->name('update');
                Route::delete('/{product}', [ProductConsumptionController::class, 'destroy'])->name('destroy');
            });
        });
    });
});
