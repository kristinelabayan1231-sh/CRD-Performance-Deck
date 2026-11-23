<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadMove;
use App\Models\Product;
use App\Services\LeadGenerator;
use App\Services\SegmentationSummary;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SegmentationSummaryController extends Controller
{
    /**
     * Product Performance → Segmentation Summary: the Segmentation Tracker's leads worked in one month (or on a single
     * day), for every CRA or one. The filter goes by the real date, like the other reports: real day $d shows the leads of
     * lead day WorkingDate::leadDayFor($d), the ones handled that day. Someone without view-all sees only their own leads.
     */
    public function index(Request $request, SegmentationSummary $summary): View
    {
        $user = $request->user();
        $canViewAll = $user->can('segmentation.view_all');
        $today = WorkingDate::realToday();

        $filters = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'cra' => ['nullable', 'string'],
            'type' => ['nullable', Rule::in(array_keys(Lead::TYPES))],
            'product' => ['nullable', 'string', 'max:255'],
        ]);

        // A specific day wins; otherwise the month picked, this month by default (up to today).
        if (! empty($filters['date'])) {
            $from = $to = CarbonImmutable::parse($filters['date']);
            $filters['month'] = $from->format('Y-m');
        } else {
            $from = CarbonImmutable::parse(($filters['month'] ?? $today->format('Y-m')).'-01');
            $to = $from->endOfMonth()->startOfDay()->min($today->max($from));
            $filters['month'] = $from->format('Y-m');
        }

        $allCras = LeadGenerator::crasBetween($from, $to);
        $filters['cra'] = $canViewAll ? ($filters['cra'] ?? 'all') : (string) $user->id;
        $cras = ctype_digit($filters['cra']) ? $allCras->where('id', (int) $filters['cra'])->values() : $allCras;
        if (! $canViewAll) {
            $cras = collect([$user]);
        }

        // The lead days worked on those real days.
        [$leadFrom, $leadTo] = [WorkingDate::leadDayFor($from), WorkingDate::leadDayFor($to)];

        $scope = Lead::query()
            ->whereDate('listed_on', '>=', $leadFrom)->whereDate('listed_on', '<=', $leadTo)
            ->when(ctype_digit($filters['cra']), fn (Builder $q) => $q->where('assigned_to', (int) $filters['cra']))
            ->when($filters['type'] ?? null, fn (Builder $q, string $type) => $q->where('lead_type', $type))
            ->when($filters['product'] ?? null, fn (Builder $q, string $product) => $q->where('product_name', $product));

        return view('product-performance.segmentation-summary', [
            'summary' => $summary->for($scope, $cras, LeadMove::departed($leadFrom, $leadTo,
                ctype_digit($filters['cra']) ? [(int) $filters['cra']] : null, $filters['type'] ?? null, $filters['product'] ?? null)),
            'filters' => $filters,
            'period' => $from->equalTo($to) ? $from->format('D, M j, Y') : $from->format('M j').'–'.$to->format('M j, Y'),
            'leadDays' => WorkingDate::leadDaysLabel($from, $to),
            'cras' => $allCras,
            'canViewAll' => $canViewAll,
            'products' => Product::crd()->orderBy('name')->pluck('name'),
        ]);
    }
}
