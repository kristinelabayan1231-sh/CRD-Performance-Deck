<?php

namespace App\Http\Controllers;

use App\Services\LeadGenerator;
use App\Services\SegmentationStats;
use App\Support\DashboardRange;
use App\Support\WorkingDate;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The customers behind the dashboard's Retained and Repeat tiles (no menu link), for the dashboard's dates:
 * Segmentation Tracker leads with Repeat Purchase = Yes bought in them. A CRA sees their own.
 */
class RepeatPurchaseController extends Controller
{
    public const SEGMENTS = [
        'retained' => ['Retained', 'FSD Leads'],
        'repeat' => ['Repeat customers', 'CRD Leads'],
    ];

    public function index(Request $request, SegmentationStats $stats): View
    {
        $user = $request->user();
        $today = WorkingDate::realToday();
        $filters = $request->validate([
            'segment' => ['nullable', 'in:'.implode(',', array_keys(self::SEGMENTS))],
            'month' => ['nullable', 'date_format:Y-m', 'before_or_equal:'.$today->format('Y-m')],
            'from' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today->toDateString()],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $segment = $filters['segment'] ?? 'retained';
        $range = DashboardRange::fromFilters($filters, $today);
        $cras = $user->can('segmentation.view_all') ? LeadGenerator::cras() : collect([$user]);

        // Both lists, so each tab shows its count.
        $lists = collect(self::SEGMENTS)->map(fn (array $labels, string $key) => $stats->repeatPurchases($cras, $key, $range->from, $range->to));

        return view('segmentation.repeat-purchases', [
            'segment' => $segment,
            'customers' => $lists[$segment],
            'counts' => $lists->map->count(),
            'range' => $range,
            'dates' => ['from' => $range->from->toDateString(), 'to' => $range->to->toDateString()],
            'canViewAll' => $user->can('segmentation.view_all'),
        ]);
    }
}
