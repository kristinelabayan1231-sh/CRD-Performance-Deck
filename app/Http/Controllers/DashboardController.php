<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Services\LeadGenerator;
use App\Services\SegmentationStats;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class DashboardController extends Controller
{
    public function __invoke(Request $request, LeadGenerator $generator, SegmentationStats $stats): View
    {
        $user = $request->user();
        $segmentation = null;

        if ($user->can('segmentation.view')) {
            // Same freshness rule as the tracker: sync today if it's over an hour old.
            try {
                $generator->syncIfStale(Lead::today());
            } catch (Throwable $e) {
                Log::warning('Dashboard lead sync failed', ['message' => $e->getMessage()]);
            }

            // Supervisors see every CRA; a CRA sees only their own numbers.
            $cras = $user->can('segmentation.view_all') ? LeadGenerator::cras() : collect([$user]);
            $segmentation = $stats->periods($cras);
        }

        return view('dashboard', ['segmentation' => $segmentation]);
    }
}
