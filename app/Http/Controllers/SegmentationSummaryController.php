<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Product;
use App\Services\LeadGenerator;
use App\Services\SegmentationSummary;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SegmentationSummaryController extends Controller
{
    /**
     * Segmentation Tracker → Summary for one month of lead days (or a single day), for every CRA or one.
     * A CRA sees only their own leads.
     */
    public function index(Request $request, SegmentationSummary $summary): View
    {
        $user = $request->user();
        $canViewAll = $user->can('segmentation.view_all');
        $today = Lead::today();

        $filters = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'cra' => ['nullable', 'string'],
            'type' => ['nullable', Rule::in(array_keys(Lead::TYPES))],
            'product' => ['nullable', 'string', 'max:255'],
        ]);

        // A specific day wins; otherwise the month picked, this month by default (up to today's lead day).
        if (! empty($filters['date'])) {
            $from = $to = CarbonImmutable::parse($filters['date']);
            $filters['month'] = $from->format('Y-m');
        } else {
            $from = CarbonImmutable::parse(($filters['month'] ?? $today->format('Y-m')).'-01');
            $to = $from->endOfMonth()->startOfDay()->min($today->max($from));
            $filters['month'] = $from->format('Y-m');
        }

        $allCras = LeadGenerator::cras();
        $filters['cra'] = $canViewAll ? ($filters['cra'] ?? 'all') : (string) $user->id;
        $cras = ctype_digit($filters['cra']) ? $allCras->where('id', (int) $filters['cra'])->values() : $allCras;
        if (! $canViewAll) {
            $cras = collect([$user]);
        }

        $scope = Lead::query()
            ->whereDate('est_out_of_stock_date', '>=', $from)->whereDate('est_out_of_stock_date', '<=', $to)
            ->when(ctype_digit($filters['cra']), fn (Builder $q) => $q->where('assigned_to', (int) $filters['cra']))
            ->when($filters['type'] ?? null, fn (Builder $q, string $type) => $q->where('lead_type', $type))
            ->when($filters['product'] ?? null, fn (Builder $q, string $product) => $q->where('product_name', $product));

        return view('segmentation.overview', [
            'summary' => $summary->for($scope, $cras),
            'filters' => $filters,
            'period' => $from->equalTo($to) ? $from->format('D, M j, Y') : $from->format('M j').'–'.$to->format('M j, Y'),
            'cras' => $allCras,
            'canViewAll' => $canViewAll,
            'products' => Product::orderBy('name')->pluck('name'),
        ]);
    }
}
