<?php

namespace App\Services;

use App\Models\CraGrossSale;
use App\Models\CvrOrderEntry;
use App\Models\PancakeEngagement;
use App\Models\PancakeOrder;
use App\Models\User;
use App\Support\PancakeAccounts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * CRA Gross Sales & IBSC: each CRA's Gross Sales and Net Income per month of a year, typed in on the page
 * (cra_gross_sales; copied once from the Actual Income cards on 2026-11-14, no longer taken from them). A CRA's month is
 * empty (null) until something is typed for it; TOTAL adds up the CRAs shown. Each month's top seller is the CRA with
 * the most Gross Sales.
 *
 * Orders and AOV come from Pancake POS: every order on a CRA's accounts (their own and their other accounts) that
 * counts in CRA sales, canceled included, deleted left out, from the day the account was CRD's (Lhea Gatdula from
 * 2026-07-29); an order the CVR Tracker counts for another CRA is theirs. AOV = the orders' sales before Pancake's
 * discount (Shecom's amount, else Pancake's) ÷ orders, as on Order Database → CRA's Performance.
 *
 * ACB = (the year's positive + negative Net Income so far) × 1.4% (segmentation.acb). Before June 2026 a month
 * only counts when its Net Income is below ₱250,000; from June 2026 every month counts.
 */
class CraGrossSales
{
    /**
     * Rows: $cras (the active CRAs) and anyone else with figures that year (a CRA who has left).
     *
     * @param  Collection<int, User>  $cras
     * @return array{months: list<CarbonImmutable>, rows: Collection<int, User>, top: array<int, int|null>, cras: array<int, array{months: array<int, array{gross: float, net: float}|null>, year: array{gross: float, net: float}|null}>, typed: array<int, array<int, array{gross: float|null, net: float|null}>>, acb: array<int, float|null>, acbTotal: float|null, pos: array<int, array{months: array<int, array{orders: int, sales: float}|null>, year: array{orders: int, sales: float}|null}>, posTotals: array<int, array{orders: int, sales: float}|null>, posYear: array{orders: int, sales: float}|null, totals: array<int, array{gross: float, net: float}|null>, year: array{gross: float, net: float}|null}
     */
    public function year(Collection $cras, int $year): array
    {
        $months = array_map(fn (int $month) => CarbonImmutable::create($year, $month, 1), range(1, 12));
        $saved = CraGrossSale::whereYear('month', $year)->get(['month', 'user_id', 'gross', 'net']);
        $others = User::whereIn('id', $saved->pluck('user_id')->unique()->diff($cras->pluck('id')))->get();
        $rows = $cras->concat($others)->unique('id')->sortBy(fn (User $user) => $user->craSortKey())->values();

        $cells = [];
        $typed = [];
        foreach ($rows as $cra) {
            foreach ($months as $index => $month) {
                $figures = $saved->first(fn (CraGrossSale $sale) => $sale->user_id === $cra->id && $sale->month->month === $month->month);
                $filled = $figures !== null && ($figures->gross !== null || $figures->net !== null);
                $cells[$cra->id][$index] = $filled ? ['gross' => (float) $figures->gross, 'net' => (float) $figures->net] : null;
                $typed[$cra->id][$index] = ['gross' => $figures?->gross, 'net' => $figures?->net];
            }
        }

        $totals = array_map(fn (int $index) => self::add(array_column($cells, $index)), array_keys($months));
        $acb = array_map(fn (array $row) => self::acb($row, $months), $cells);
        // The month's top seller: the CRA with the most Gross Sales (the first in CRA order on a tie).
        $top = array_map(function (int $index) use ($cells): ?int {
            $best = null;
            foreach ($cells as $craId => $row) {
                if ($row[$index] !== null && ($best === null || $row[$index]['gross'] > $cells[$best][$index]['gross'])) {
                    $best = $craId;
                }
            }

            return $best;
        }, array_keys($months));

        $pos = $this->posOrders($rows, $year);
        $posCells = [];
        foreach ($rows as $cra) {
            $posCells[$cra->id] = ['months' => array_map(fn (int $index) => $pos[$cra->id][$index] ?? null, array_keys($months))];
            $posCells[$cra->id]['year'] = self::addOrders($posCells[$cra->id]['months']);
        }
        $posTotals = array_map(fn (int $index) => self::addOrders(array_map(fn (array $row) => $row['months'][$index], $posCells)), array_keys($months));

        return [
            'months' => $months,
            'rows' => $rows,
            'pos' => $posCells,
            'posTotals' => $posTotals,
            'posYear' => self::addOrders($posTotals),
            'cras' => array_map(fn (array $row) => ['months' => $row, 'year' => self::add($row)], $cells),
            'typed' => $typed,
            'acb' => $acb,
            'acbTotal' => array_filter($acb, fn (?float $value) => $value !== null) === [] ? null : (float) array_sum($acb),
            'totals' => $totals,
            'top' => $top,
            'year' => self::add($totals),
        ];
    }

    /**
     * Each CRA's Pancake POS orders and their sales per month of the year: CRA id => month index (0 = January) =>
     * orders and sales.
     *
     * @param  Collection<int, User>  $cras
     * @return array<int, array<int, array{orders: int, sales: float}>>
     */
    private function posOrders(Collection $cras, int $year): array
    {
        $craBySeller = PancakeAccounts::craBySeller($cras->filter(fn (User $cra) => filled($cra->pancake_name))->values());
        if ($craBySeller->isEmpty()) {
            return [];
        }

        $sales = 'coalesce(shecom_sales, total_price + coalesce(total_discount, 0))';
        $orders = fn () => PancakeAccounts::whereSoldAsCrd(PancakeOrder::query()->crdSales())
            ->where('ordered_on', '>=', $year.'-01-01')->where('ordered_on', '<', ($year + 1).'-01-01')
            ->where(fn ($query) => $query->whereNull('status')->orWhere('status', '!=', CraPerformance::DELETED));
        // Orders the CVR Tracker counts for another CRA are that CRA's (as on Order Database → CRA's Performance).
        $claimed = CvrOrderEntry::claimedAccounts();
        $claimedIds = array_map('strval', array_keys($claimed));

        $found = [];
        $count = function (?string $sellerKey, string $day, int $orderCount, float $amount) use (&$found, $craBySeller): void {
            $cra = $craBySeller[$sellerKey] ?? null;
            if ($cra === null) {
                return;
            }
            $index = (int) substr($day, 5, 2) - 1;
            $found[$cra->id][$index]['orders'] = ($found[$cra->id][$index]['orders'] ?? 0) + $orderCount;
            $found[$cra->id][$index]['sales'] = ($found[$cra->id][$index]['sales'] ?? 0.0) + $amount;
        };

        $orders()->whereIn('seller_name', $this->sellerNames($craBySeller->keys()->all()))
            ->when($claimedIds !== [], fn ($query) => $query->whereNotIn('pancake_order_id', $claimedIds))
            ->toBase()->selectRaw("seller_name, ordered_on, count(*) as order_count, sum({$sales}) as sales")
            ->groupBy('seller_name', 'ordered_on')->get()
            ->each(fn (object $row) => $count(PancakeEngagement::staffKey($row->seller_name), (string) $row->ordered_on, (int) $row->order_count, (float) $row->sales));

        foreach (array_chunk($claimedIds, 1000) as $chunk) {
            $orders()->whereIn('pancake_order_id', $chunk)->toBase()
                ->selectRaw("pancake_order_id, ordered_on, {$sales} as sales")->get()
                ->each(fn (object $row) => $count($claimed[$row->pancake_order_id] ?? null, (string) $row->ordered_on, 1, (float) $row->sales));
        }

        return $found;
    }

    /**
     * The seller names saved on orders that match these staff keys (seller names are saved as Pancake sends them).
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function sellerNames(array $keys): array
    {
        return PancakeOrder::query()->whereNotNull('seller_name')->distinct()->pluck('seller_name')
            ->filter(fn (string $name) => in_array(PancakeEngagement::staffKey($name), $keys, true))
            ->values()->all();
    }

    /**
     * Add up months' POS orders; null when none have any.
     *
     * @param  array<int, array{orders: int, sales: float}|null>  $cells
     * @return array{orders: int, sales: float}|null
     */
    private static function addOrders(array $cells): ?array
    {
        $filled = array_filter($cells);

        return $filled === [] ? null : [
            'orders' => (int) array_sum(array_column($filled, 'orders')),
            'sales' => (float) array_sum(array_column($filled, 'sales')),
        ];
    }

    /**
     * A CRA's ACB for the year: the Net Income of the months that count × the rate; null without any.
     *
     * @param  array<int, array{gross: float, net: float}|null>  $row
     * @param  list<CarbonImmutable>  $months
     */
    private static function acb(array $row, array $months): ?float
    {
        $cap = (float) config('segmentation.acb.cap');
        $cappedUntil = CarbonImmutable::parse(config('segmentation.acb.capped_until'));
        $counted = [];
        foreach ($row as $index => $cell) {
            if ($cell !== null && ($months[$index]->gte($cappedUntil) || $cell['net'] < $cap)) {
                $counted[] = $cell['net'];
            }
        }

        return $counted === [] ? null : round(array_sum($counted) * (float) config('segmentation.acb.rate'), 2);
    }

    /**
     * Add up the cells that have figures; null when none do.
     *
     * @param  array<int, array{gross: float, net: float}|null>  $cells
     * @return array{gross: float, net: float}|null
     */
    private static function add(array $cells): ?array
    {
        $filled = array_filter($cells);

        return $filled === [] ? null : [
            'gross' => array_sum(array_column($filled, 'gross')),
            'net' => array_sum(array_column($filled, 'net')),
        ];
    }
}
