<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Services\LeadGenerator;
use App\Services\PancakeSync;
use App\Support\DashboardVersion;
use App\Support\WorkingDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Polled every minute by an open dashboard: starts the same syncs the dashboard does when their
 * data is stale (so it stays live while the scheduler sleeps), and returns the data stamp.
 */
class DashboardLiveController extends Controller
{
    public function __invoke(Request $request, LeadGenerator $generator, PancakeSync $pancake): JsonResponse
    {
        $user = $request->user();
        $realToday = WorkingDate::realToday();

        if ($user->can('segmentation.view')) {
            $generator->syncLater(Lead::today());
        }

        if ($user->can('conversion.view') && PancakeSync::isStale($realToday) && ! PancakeSync::isRunning($realToday)) {
            $pancake->syncLater($realToday);
        }

        return response()->json(['version' => DashboardVersion::current()]);
    }
}
