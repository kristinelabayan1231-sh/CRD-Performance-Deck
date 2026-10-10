<?php

namespace App\Http\Controllers;

use App\Models\CustomerHistory;
use App\Models\LogisticsOrder;
use App\Services\CustomerDatabase;
use App\Services\LogisticsRetention;
use App\Services\PancakeClient;
use App\Services\PancakeSync;
use App\Support\MonthWeeks;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CustomerDatabaseController extends Controller
{
    public const PERIODS = ['all' => 'All', 'today' => 'Today', 'week' => 'Week', 'month' => 'Month'];

    public function index(Request $request, CustomerDatabase $customers): View
    {
        $filters = $request->validate([
            'period' => ['nullable', Rule::in(array_keys(self::PERIODS))],
            'segment' => ['nullable', Rule::in(array_keys(CustomerDatabase::SEGMENTS))],
            'sort' => ['nullable', Rule::in(array_keys(CustomerDatabase::SORTS))],
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ], [
            'to.after_or_equal' => 'The "to" date must be on or after the "from" date.',
        ]);

        // A From–To range replaces the Today / Week / Month buttons.
        $hasRange = filled($filters['from'] ?? null) || filled($filters['to'] ?? null);
        $period = $hasRange ? 'range' : ($filters['period'] ?? 'all');
        $range = $hasRange
            ? $this->customRange($filters['from'] ?? null, $filters['to'] ?? null, WorkingDate::realToday())
            : $this->range($period, WorkingDate::realToday());
        $query = ['from' => $range['from'], 'to' => $range['to'], 'search' => $filters['search'] ?? null];

        $counts = $customers->cachedCounts($query);

        return view('customers.index', [
            'customers' => $customers->cachedList([...$query, 'segment' => $filters['segment'] ?? null, 'sort' => $filters['sort'] ?? 'spent'], $counts),
            'counts' => $counts,
            'filters' => [...$filters, 'period' => $period],
            'range' => $range,
            'periods' => self::PERIODS,
            'segments' => CustomerDatabase::SEGMENTS,
            'sorts' => CustomerDatabase::SORTS,
            'fetchedAt' => LogisticsRetention::fetchedAt(),
            'today' => WorkingDate::realToday(),
            'history' => Cache::remember('customers.history_progress', now()->addMinutes(CustomerDatabase::CACHE_MINUTES), fn () => $customers->historyProgress()),
        ]);
    }

    /**
     * The customer pop-up's contents (status breakdown, product CLTV, orders).
     */
    public function show(CustomerDatabase $customers, PancakeSync $pancake, PancakeClient $client, string $phoneKey): View
    {
        // Delivered orders the backfill hasn't reached yet: fetch them so every amount shows.
        $customers->fetchMissingOrders($phoneKey, $pancake);

        // Their orders before the first covered day, if the scheduled check hasn't reached them yet.
        if (! CustomerHistory::where('phone_key', $phoneKey)->exists()) {
            rescue(fn () => $customers->checkHistories([$phoneKey], $client));
        }

        $profile = $customers->profile($phoneKey);

        abort_if($profile === null, 404);

        return view('customers._profile', ['customer' => $profile]);
    }

    /**
     * Deliveries counted for the period: today, this week (1–7, 8–14… from the 1st), this month, or all.
     *
     * @return array{from: ?CarbonImmutable, to: ?CarbonImmutable, label: string}
     */
    private function range(string $period, CarbonImmutable $today): array
    {
        $month = $today->startOfMonth();

        return match ($period) {
            'today' => ['from' => $today, 'to' => $today, 'label' => $today->format('D, M j')],
            'week' => (function () use ($month, $today) {
                $week = MonthWeeks::for($month)[MonthWeeks::containing($month, $today) - 1];

                return ['from' => $week['start'], 'to' => $week['end'], 'label' => 'Week '.$week['number'].' · '.$week['label']];
            })(),
            'month' => ['from' => $month, 'to' => $month->endOfMonth()->startOfDay(), 'label' => $month->format('F Y')],
            default => ['from' => null, 'to' => null, 'label' => 'Delivered since '.CarbonImmutable::parse(LogisticsOrder::coveredFrom())->format('M j, Y')],
        };
    }

    /**
     * A From–To range; an open end runs from the first covered day or to today.
     *
     * @return array{from: CarbonImmutable, to: CarbonImmutable, label: string}
     */
    private function customRange(?string $from, ?string $to, CarbonImmutable $today): array
    {
        $from = CarbonImmutable::parse($from ?? LogisticsOrder::coveredFrom());
        $to = $to ? CarbonImmutable::parse($to) : $today->max($from);
        $label = $from->equalTo($to) ? $from->format('M j, Y') : $from->format('M j, Y').' – '.$to->format('M j, Y');

        return ['from' => $from, 'to' => $to, 'label' => $label];
    }
}
