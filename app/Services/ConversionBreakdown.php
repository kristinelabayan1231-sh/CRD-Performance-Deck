<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadMove;
use App\Models\PancakeEngagement;
use App\Models\PancakeOrder;
use App\Models\User;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Conversion Breakdown numbers per CRA per day.
 *
 * Orders BC / SC = the CRA's Pancake POS orders that day tagged "CRD - BROADCAST" / "CRD - SEGMENTATION"
 * (canceled and deleted orders don't count), with the CRAs' corrections in the CVR Tracker: a changed Type of Order,
 * and an order counted for another CRA (e.g. made under another account). Gross BC / SC = those orders' totals.
 * Engagements = the CRA's Pancake customer engagements (Chat → Analytics → Engagements).
 * Leads = Segmentation Tracker leads assigned to the CRA for the lead day worked that day (base 70; actual
 * count shown), leads added from their POS sales included as in the Segmentation Tracker. With a working date set,
 * that's the lead day the gap points to (Oct 7 → Sept 7).
 * Gross uses Shecom's sales per order, which leaves out the child (TSD) row; Pancake's total until it is synced.
 * BC conv % = Orders BC ÷ Engagements. SC conv % = Orders SC ÷ Leads.
 * Total conv % = (Orders BC + Orders SC) ÷ (Engagements + Leads). Gross sales = Gross BC + Gross SC.
 */
class ConversionBreakdown
{
    public const COUNTS = ['bc_orders', 'sc_orders', 'engagements', 'leads'];

    /**
     * @param  Collection<int, User>  $cras
     * @return array<int, array<string, array<string, int|float|null>>> cra id => Y-m-d => metrics
     */
    public function days(Collection $cras, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ids = $cras->pluck('id');
        $accounts = self::accounts($cras);

        // Leads worked on a real day are from its paired lead day (Settings → Working Date).
        $lag = WorkingDate::lagDays();
        $leads = [];
        Lead::whereIn('assigned_to', $ids)
            ->whereDate('listed_on', '>=', $from->subDays($lag))->whereDate('listed_on', '<=', $to->subDays($lag))
            ->selectRaw('assigned_to as cra, date(listed_on) as day, count(*) as n')->groupBy('cra', 'day')->get()
            ->each(function ($row) use (&$leads, $lag) {
                $leads[(int) $row->cra][CarbonImmutable::parse($row->day)->addDays($lag)->toDateString()] = (int) $row->n;
            });
        // Plus leads the backlog fill moved off those lead days.
        foreach (LeadMove::countsByCraAndDay(LeadMove::departed($from->subDays($lag), $to->subDays($lag), $ids)) as $cra => $perDay) {
            foreach ($perDay as $day => $n) {
                $real = CarbonImmutable::parse($day)->addDays($lag)->toDateString();
                $leads[$cra][$real] = ($leads[$cra][$real] ?? 0) + $n;
            }
        }

        $engagements = [];
        PancakeEngagement::whereIn('staff_name', $accounts->keys())
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->get()
            ->each(function (PancakeEngagement $row) use (&$engagements, $accounts) {
                $cra = $accounts[$row->staff_name];
                $day = $row->date->toDateString();
                $engagements[$cra][$day] = ($engagements[$cra][$day] ?? 0) + $row->engagements;
            });

        $orders = $this->orders($accounts, $from, $to);

        $result = [];
        foreach ($cras as $cra) {
            for ($day = $from->startOfDay(); $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
                $key = $day->toDateString();
                $result[$cra->id][$key] = self::derive([
                    'bc_orders' => $orders[$cra->id][$key]['bc_orders'] ?? 0,
                    'sc_orders' => $orders[$cra->id][$key]['sc_orders'] ?? 0,
                    'bc_gross' => $orders[$cra->id][$key]['bc_gross'] ?? 0.0,
                    'sc_gross' => $orders[$cra->id][$key]['sc_gross'] ?? 0.0,
                    'engagements' => $engagements[$cra->id][$key] ?? 0,
                    'leads' => $leads[$cra->id][$key] ?? 0,
                ]);
            }
        }

        return $result;
    }

    /**
     * Tagged orders per CRA per day: counts (confirmed orders, not canceled or deleted) and gross sales
     * (every status) for broadcast and segmentation, with the CVR Tracker's corrections (taggedOrders()).
     *
     * @param  Collection<string, int>  $accounts  Pancake account name => CRA id
     * @return array<int, array<string, array{bc_orders: int, sc_orders: int, bc_gross: float, sc_gross: float}>>
     */
    public function orders(Collection $accounts, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($accounts->isEmpty()) {
            return [];
        }

        $out = [];

        self::taggedOrders($accounts, $from, $to)
            ->get()
            ->each(function (PancakeOrder $order) use (&$out, $accounts) {
                $cra = self::craFor($order, $accounts);
                $day = $order->ordered_on->toDateString();
                $prefix = $order->counted_type === PancakeOrder::BROADCAST ? 'bc' : 'sc';
                $out[$cra][$day] ??= ['bc_orders' => 0, 'sc_orders' => 0, 'bc_gross' => 0.0, 'sc_gross' => 0.0];
                $out[$cra][$day]["{$prefix}_orders"] += $order->isCounted() ? 1 : 0;
                $out[$cra][$day]["{$prefix}_gross"] += $order->sales();
            });

        return $out;
    }

    /**
     * The CRAs' (by $accounts) Pancake orders created $from–$to tagged CRD - BROADCAST or CRD - SEGMENTATION, every
     * status, as the CVR Tracker counts them: an order counted for another CRA there goes to that CRA (also when made
     * under an account not in $accounts), and its Type of Order there wins over the tag. Each order carries
     * counted_type (broadcast / segmentation) and counted_for (the CVR Tracker's CRA id, or null: its seller's CRA).
     *
     * @param  Collection<string, int>  $accounts  Pancake account name => CRA id
     */
    public static function taggedOrders(Collection $accounts, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return PancakeOrder::crdProducts()
            ->leftJoin('cvr_order_entries as cvr', 'cvr.pancake_order_id', '=', 'pancake_orders.pancake_order_id')
            ->whereIn('pancake_orders.conversion_type', [PancakeOrder::BROADCAST, PancakeOrder::SEGMENTATION])
            ->where('pancake_orders.ordered_on', '>=', $from->toDateString())->where('pancake_orders.ordered_on', '<', $to->addDay()->toDateString())
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q->whereNull('cvr.user_id')->whereIn('pancake_orders.seller_name', $accounts->keys()))
                ->orWhereIn('cvr.user_id', $accounts->values()))
            ->select('pancake_orders.*')
            ->selectRaw('coalesce(cvr.order_type, pancake_orders.conversion_type) as counted_type, cvr.user_id as counted_for');
    }

    /**
     * The CRA an order from taggedOrders() counts for.
     *
     * @param  Collection<string, int>  $accounts
     */
    public static function craFor(PancakeOrder $order, Collection $accounts): ?int
    {
        return $order->counted_for !== null ? (int) $order->counted_for : ($accounts[$order->seller_name] ?? null);
    }

    /**
     * Normalised Pancake account name => CRA id, for CRAs with an account set.
     *
     * @param  Collection<int, User>  $cras
     * @return Collection<string, int>
     */
    public static function accounts(Collection $cras): Collection
    {
        return $cras->filter(fn (User $cra) => $cra->pancake_name)
            ->mapWithKeys(fn (User $cra) => [PancakeEngagement::staffKey($cra->pancake_name) => $cra->id]);
    }

    /**
     * Add up several days (or CRAs) and work the rates out again from the totals.
     *
     * @param  iterable<array<string, int|float|null>>  $rows
     * @return array<string, int|float|null>
     */
    public static function sum(iterable $rows): array
    {
        $totals = [...array_fill_keys(self::COUNTS, 0), 'bc_gross' => 0.0, 'sc_gross' => 0.0];

        foreach ($rows as $row) {
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $row[$key];
            }
        }

        return self::derive($totals);
    }

    /**
     * @param  array{bc_orders: int, sc_orders: int, bc_gross: float, sc_gross: float, engagements: int, leads: int}  $base
     * @return array<string, int|float|null>
     */
    private static function derive(array $base): array
    {
        $orders = $base['bc_orders'] + $base['sc_orders'];
        $reach = $base['engagements'] + $base['leads'];

        return [
            ...$base,
            'orders' => $orders,
            'reach' => $reach,
            'gross' => $base['bc_gross'] + $base['sc_gross'],
            'bc_rate' => $base['engagements'] ? $base['bc_orders'] / $base['engagements'] : null,
            'sc_rate' => $base['leads'] ? $base['sc_orders'] / $base['leads'] : null,
            'total_rate' => $reach ? $orders / $reach : null,
        ];
    }
}
