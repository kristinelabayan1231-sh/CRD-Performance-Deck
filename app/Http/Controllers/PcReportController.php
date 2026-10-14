<?php

namespace App\Http\Controllers;

use App\Services\LeadGenerator;
use App\Support\ProductGroups;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PcReportController extends Controller
{
    /**
     * One set per CRA for the picked product and day: every page in the product's group.
     * UI preview: the numbers are not fetched yet.
     */
    public function index(Request $request): View
    {
        $today = WorkingDate::realToday();

        $filters = $request->validate([
            'product' => ['nullable', 'string', 'max:100'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'cra' => ['nullable', 'integer'],
        ]);

        $groups = ProductGroups::all();
        $group = collect($groups)->firstWhere('slug', $filters['product'] ?? null) ?? $groups[0];
        $day = CarbonImmutable::parse($filters['date'] ?? $today->toDateString())->min($today);

        $user = $request->user();
        $allCras = $user->can('pc_report.view_all') ? LeadGenerator::cras() : null;
        $cras = $allCras === null ? collect([$user]) : $allCras->when(
            $filters['cra'] ?? null,
            fn ($cras, $id) => $cras->where('id', (int) $id)->values(),
        );

        return view('pc-report.index', [
            'groups' => $groups,
            'group' => $group,
            'day' => $day,
            'today' => $today,
            'allCras' => $allCras,
            'cras' => $cras,
            'craId' => $filters['cra'] ?? null,
            'canEdit' => $user->can('pc_report.manage'),
        ]);
    }
}
