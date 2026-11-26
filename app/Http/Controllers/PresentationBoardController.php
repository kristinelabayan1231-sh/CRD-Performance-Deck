<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\User;
use App\Services\ExclusiveLeads;
use App\Services\PancakePageBackfill;
use App\Services\PresentationBoard;
use App\Services\PresentationBoardCras;
use App\Support\UpsellBreakdown;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PresentationBoardController extends Controller
{
    public function __invoke(Request $request, PresentationBoard $board, PresentationBoardCras $cras, PancakePageBackfill $backfill, ExclusiveLeads $exclusive): View
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m'],
            'to' => ['nullable', 'date_format:Y-m', 'after_or_equal:from'],
            'period' => ['nullable', Rule::in(array_keys(PresentationBoard::PERIODS))],
            'products_from' => ['nullable', 'date_format:Y-m-d'],
            'products_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:products_from'],
        ], [
            'to.after_or_equal' => 'The "to" month must be on or after the "from" month.',
            'products_to.after_or_equal' => 'Top products: the "to" date must be on or after the "from" date.',
        ]);

        $snapshot = $board->snapshot();
        $period = $filters['period'] ?? 'weekly';

        if (! $snapshot) {
            return view('presentation-board.index', ['snapshot' => null, 'progress' => $backfill->progress(), 'period' => $period]);
        }

        $months = array_keys($snapshot['months']);
        $from = max($filters['from'] ?? $months[0], $months[0]);
        $to = min($filters['to'] ?? end($months), end($months));

        $figures = $board->range($snapshot, $from, $to, $period);
        $craSnapshot = $cras->snapshot();
        // Top products has its own dates (any days of the snapshot's year), the months above until picked.
        $firstDay = $months[0].'-01';
        $lastDay = $snapshot['today'];
        $productsFrom = min(max($filters['products_from'] ?? $from.'-01', $firstDay), $lastDay);
        $productsTo = max(min($filters['products_to'] ?? CarbonImmutable::parse($to.'-01')->endOfMonth()->toDateString(), $lastDay), $productsFrom);
        $topProducts = $board->products($snapshot, $productsFrom, $productsTo);
        $monthKeys = array_column($figures['months'], 'key');
        // The month before the range, so Top products and CRA performance can compare the first month picked with it.
        $monthBefore = CarbonImmutable::parse($from.'-01')->subMonth()->format('Y-m');
        $monthBefore = isset($snapshot['months'][$monthBefore]) ? $monthBefore : null;
        $compareKeys = $monthBefore ? [$monthBefore, ...$monthKeys] : $monthKeys;
        $tsdNames = Product::where('is_tsd', true)->pluck('name')->all();

        return view('presentation-board.index', [
            ...$figures,
            'productMonths' => $this->productMonths($board, $snapshot, $compareKeys),
            'craMonths' => $craSnapshot ? $this->craMonths($cras, $craSnapshot, $compareKeys) : [],
            'monthBefore' => $monthBefore,
            'exclusive' => $craSnapshot ? $this->exclusive($exclusive, $craSnapshot, $monthKeys) : [],
            'products' => $topProducts['products'],
            'others' => $topProducts['others'],
            'topProducts' => [...$topProducts, 'first_day' => $firstDay, 'last_day' => $lastDay, 'picked' => isset($filters['products_from']) || isset($filters['products_to'])],
            'tsdNames' => $tsdNames,
            'upsellSplit' => UpsellBreakdown::totals($topProducts, $tsdNames),
            'craPerformance' => $craSnapshot ? $cras->range($craSnapshot, $from, $to) : null,
            // The month range's products, as the other figures.
            'insights' => $board->insights($figures['months'], $figures['totals'], $figures['products']),
            'wins' => $board->wins($figures['months'], $figures['totals'], CarbonImmutable::parse($snapshot['today'])),
            'snapshot' => $snapshot,
            'monthOptions' => collect($months)->mapWithKeys(fn (string $month) => [$month => CarbonImmutable::parse($month.'-01')->format('M Y')])->all(),
            'from' => $from,
            'to' => $to,
            'period' => $period,
            'progress' => $backfill->progress(),
        ]);
    }

    /**
     * Each month's top products, for the product trend lines: the same products() a month at a time.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  list<string>  $months
     * @return array<string, array<string, array{sales: float, orders: int, upsold: float}>> month => product name => figures
     */
    private function productMonths(PresentationBoard $board, array $snapshot, array $months): array
    {
        return collect($months)->mapWithKeys(fn (string $month) => [$month => collect($board->products($snapshot, $month.'-01', CarbonImmutable::parse($month.'-01')->endOfMonth()->toDateString())['products'])
            ->mapWithKeys(fn (array $product) => [$product['name'] => ['sales' => (float) $product['sales'], 'orders' => (int) $product['orders'], 'upsold' => (float) $product['upsold_value']]])
            ->all()])->all();
    }

    /**
     * Each month's figures per CRA, for the CRA cards' trend lines, month popups and comparison: the same range() a
     * month at a time.
     *
     * @param  array<string, mixed>  $craSnapshot
     * @param  list<string>  $months
     * @return array<string, array<int, array{gross: float, orders: int, aov: ?float, capacity: ?float, ni: ?float}>> month => user id => figures
     */
    private function craMonths(PresentationBoardCras $cras, array $craSnapshot, array $months): array
    {
        return collect($months)->mapWithKeys(fn (string $month) => [$month => collect($cras->range($craSnapshot, $month, $month)['rows'])
            ->mapWithKeys(fn (array $row) => [$row['user']->id => [
                'gross' => (float) $row['gross'],
                'orders' => $row['orders'],
                'aov' => $row['aov'],
                'capacity' => $row['capacity'],
                'ni' => $row['ni'],
            ]])
            ->all()])->all();
    }

    /**
     * Each exclusive CRA's months in the range, from the CRA snapshot (presentation-board:refresh-cras); none until a
     * snapshot that has them.
     *
     * @param  array<string, mixed>  $craSnapshot
     * @param  list<string>  $months
     * @return list<array{user: User, months: array<string, array<string, mixed>>}>
     */
    private function exclusive(ExclusiveLeads $exclusive, array $craSnapshot, array $months): array
    {
        $saved = $craSnapshot['exclusive'] ?? [];

        return $exclusive->cras()
            ->map(fn (User $cra) => ['user' => $cra, 'months' => array_intersect_key($saved[$cra->id] ?? [], array_flip($months))])
            ->filter(fn (array $row) => $row['months'] !== [])->values()->all();
    }
}
