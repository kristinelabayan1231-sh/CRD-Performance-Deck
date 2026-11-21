<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ExclusiveLeads;
use App\Services\PancakePageBackfill;
use App\Services\PresentationBoard;
use App\Services\PresentationBoardCras;
use App\Support\WorkingDate;
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

        return view('presentation-board.index', [
            ...$figures,
            'productMonths' => $this->productMonths($board, $snapshot, $monthKeys),
            'craMonths' => $craSnapshot ? $this->craMonths($cras, $craSnapshot, $monthKeys) : [],
            'exclusive' => $this->exclusive($exclusive, $monthKeys),
            'products' => $topProducts['products'],
            'others' => $topProducts['others'],
            'topProducts' => [...$topProducts, 'first_day' => $firstDay, 'last_day' => $lastDay, 'picked' => isset($filters['products_from']) || isset($filters['products_to'])],
            'craPerformance' => $craSnapshot ? $cras->range($craSnapshot, $from, $to) : null,
            // The month range's products, as the other figures.
            'insights' => $board->insights($figures['months'], $figures['totals'], $figures['products']),
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
     * @return array<string, array<string, array{sales: float, orders: int}>> month => product name => figures
     */
    private function productMonths(PresentationBoard $board, array $snapshot, array $months): array
    {
        return collect($months)->mapWithKeys(fn (string $month) => [$month => collect($board->products($snapshot, $month.'-01', CarbonImmutable::parse($month.'-01')->endOfMonth()->toDateString())['products'])
            ->mapWithKeys(fn (array $product) => [$product['name'] => ['sales' => (float) $product['sales'], 'orders' => (int) $product['orders']]])
            ->all()])->all();
    }

    /**
     * Each month's gross per CRA, for the CRA cards' trend lines: the same range() a month at a time.
     *
     * @param  array<string, mixed>  $craSnapshot
     * @param  list<string>  $months
     * @return array<string, array<int, float>> month => user id => gross
     */
    private function craMonths(PresentationBoardCras $cras, array $craSnapshot, array $months): array
    {
        return collect($months)->mapWithKeys(fn (string $month) => [$month => collect($cras->range($craSnapshot, $month, $month)['rows'])
            ->mapWithKeys(fn (array $row) => [$row['user']->id => (float) $row['gross']])
            ->all()])->all();
    }

    /**
     * The exclusive CRAs' months in the range, as the Exclusive Leads Dashboard counts them: month() for the list, its
     * targets and days, salesSplit() for exclusive vs normal sales. Months before a CRA's exclusive start or after today
     * are left out.
     *
     * @param  list<string>  $months
     * @return list<array{user: User, months: array<string, array{figures: array<string, mixed>, sales: array<string, array{orders: int, gross: float}>}>}>
     */
    private function exclusive(ExclusiveLeads $exclusive, array $months): array
    {
        $today = WorkingDate::realToday();

        return $exclusive->cras()->map(function (User $cra) use ($exclusive, $months, $today) {
            $start = WorkingDate::realDayFor($cra->exclusive_from)->format('Y-m');
            $figures = [];
            foreach ($months as $month) {
                if ($month < $start || $month > $today->format('Y-m')) {
                    continue;
                }
                $day = CarbonImmutable::parse($month.'-01');
                $figures[$month] = [
                    'figures' => $exclusive->month($cra, $day),
                    'sales' => $exclusive->salesSplit($cra, $day, $day->endOfMonth()->startOfDay()->min($today)),
                ];
            }

            return ['user' => $cra, 'months' => $figures];
        })->filter(fn (array $row) => $row['months'] !== [])->values()->all();
    }
}
