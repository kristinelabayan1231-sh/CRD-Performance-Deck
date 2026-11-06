<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadMove;
use App\Models\LogisticsOrder;
use App\Models\PancakeOrder;
use App\Models\PancakePage;
use App\Models\User;
use App\Support\DashboardRange;
use App\Support\MonthWeeks;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Presentation Board: the CRD team's year so far, per day, kept as a snapshot because it reads the whole year's orders.
 *
 * Leads = customers whose est. out-of-stock date (delivered + qty × consumption days − 1) falls in the month, on a CRD
 * product and a page in Settings → Pancake Pages: one customer = one lead per month, on their first out-of-stock day.
 * CRD = sold by a CRD team account (Settings → Pancake Accounts); FSD = not yet handled by a CRA by then (no earlier CRD
 * order, no lead assigned). In both in one month, they count under CRD. Orders whose page isn't known yet are kept apart
 * as waiting until PancakePageBackfill finishes their month. Added = a customer's first delivery ever, from FSD.
 * Retained, Repeat and Conversion are the Dashboard's, month by month; churned and for reactivation are Customer
 * Churn's, by the end of each customer's reorder window; Sales are Conversion Breakdown's gross sales of every CRA.
 */
class PresentationBoard
{
    public const CACHE_KEY = 'presentation-board.snapshot';

    public const EVERY_HOURS = 2;

    /** Churned needs a tracker lead, and those start with June 2026's out-of-stock dates: their 30 days end from July. Earlier months are partial and left out of the overall churn rate. */
    public const CHURN_COMPLETE_FROM = '2026-07';

    public const OTHERS = 'Others';

    public const PERIODS = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'];

    private const SUMMED = [
        'fsd_leads', 'crd_leads', 'fsd_waiting', 'crd_waiting', 'added', 'added_waiting',
        'fsd_due', 'fsd_lost', 'fsd_reactivation', 'crd_due', 'crd_lost', 'crd_reactivation', 'all_due', 'all_lost', 'all_reactivation',
        'retained', 'retained_base', 'repeat', 'repeat_base', 'gross', 'shecom_gross', 'orders',
    ];

    public function __construct(
        private ProductCatalog $catalog,
        private CraProductSales $productSales,
        private DeliveredRetention $deliveredRetention,
        private SegmentationStats $stats,
        private CustomerChurn $churn,
        private SalesGoalProgress $goals,
        private PancakePageBackfill $backfill,
    ) {}

    /**
     * The last snapshot; null until the first one is taken (presentation-board:refresh).
     *
     * @return array<string, mixed>|null
     */
    public function snapshot(): ?array
    {
        return Cache::get(self::CACHE_KEY);
    }

    /**
     * Take the snapshot again.
     *
     * @return array<string, mixed>
     */
    public function refresh(): array
    {
        $snapshot = $this->build(WorkingDate::realToday());
        Cache::forever(self::CACHE_KEY, $snapshot);

        return $snapshot;
    }

    /**
     * The year up to $today.
     *
     * @return array{built_at: string, today: string, days: array<string, array<string, int|float>>, months: array<string, array<string, mixed>>}
     */
    public function build(CarbonImmutable $today): array
    {
        $yearStart = $today->startOfYear();
        $days = [];
        for ($day = $yearStart; $day->lessThanOrEqualTo($today); $day = $day->addDay()) {
            $days[$day->toDateString()] = array_fill_keys(self::SUMMED, 0);
        }
        $months = [];
        for ($month = $yearStart; $month->lessThanOrEqualTo($today); $month = $month->addMonth()) {
            $months[$month->format('Y-m')] = ['products' => [], 'conversion' => null, 'source' => null];
        }

        $this->addLeads($days, $yearStart, $today);
        $cras = LeadGenerator::cras();

        foreach (array_keys($months) as $key) {
            $from = CarbonImmutable::parse($key.'-01');
            $to = $from->endOfMonth()->startOfDay()->min($today);
            $months[$key]['source'] = $this->addRetention($days, $cras, $from, $to);
            $months[$key]['conversion'] = $this->conversion($cras, $from, $to);
            $this->addChurn($days, $from, $to);
        }

        $this->addSales($days, $months, $cras, $yearStart, $today);

        return ['built_at' => now()->toIso8601String(), 'today' => $today->toDateString(), 'days' => $days, 'months' => $months];
    }

    /**
     * The figures for months $from–$to (Y-m) of a snapshot: a row per month and per $period, and the totals. Totals
     * add the months up, so a customer counts once per month.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array{months: list<array<string, mixed>>, totals: array<string, mixed>, periods: list<array<string, mixed>>, products: list<array<string, mixed>>}
     */
    public function range(array $snapshot, string $from, string $to, string $period): array
    {
        $byMonth = collect($snapshot['days'])
            ->filter(fn (array $day, string $date) => substr($date, 0, 7) >= $from && substr($date, 0, 7) <= $to)
            ->groupBy(fn (array $day, string $date) => substr($date, 0, 7), preserveKeys: true);

        $months = $byMonth->map(function (Collection $days, string $key) use ($snapshot) {
            $conversion = $snapshot['months'][$key]['conversion'];

            return [
                ...$this->summed($days),
                'key' => $key,
                'label' => CarbonImmutable::parse($key.'-01')->format('M'),
                'source' => $snapshot['months'][$key]['source'],
                'conversion' => $conversion,
                'conversion_rate' => self::rate($conversion['all']['customers'] ?? 0, $conversion['all']['base'] ?? 0),
                'churn_partial' => $key < self::CHURN_COMPLETE_FROM,
            ];
        })->values();

        $withConversion = $months->filter(fn (array $month) => $month['conversion'] !== null);
        $products = [];
        foreach ($months as $month) {
            foreach ($snapshot['months'][$month['key']]['products'] as $name => $figures) {
                foreach ($figures as $key => $value) {
                    $products[$name][$key] = ($products[$name][$key] ?? 0) + $value;
                }
            }
        }
        $gross = (float) $months->sum('gross');

        return [
            'months' => $months->all(),
            'totals' => [
                ...$this->summed($months),
                'conversion_customers' => $withConversion->sum(fn (array $month) => $month['conversion']['all']['customers']),
                'conversion_base' => $withConversion->sum(fn (array $month) => $month['conversion']['all']['base']),
                'conversion_rate' => self::rate(
                    $withConversion->sum(fn (array $month) => $month['conversion']['all']['customers']),
                    $withConversion->sum(fn (array $month) => $month['conversion']['all']['base']),
                ),
                ...$this->overallChurn($months),
                'churn_partial' => $months->contains('churn_partial', true),
                'conversion_tabs' => collect(['sc', 'bc'])->mapWithKeys(fn (string $tab) => [$tab => self::rate(
                    $withConversion->sum(fn (array $month) => $month['conversion'][$tab]['customers']),
                    $withConversion->sum(fn (array $month) => $month['conversion'][$tab]['base']),
                )])->all(),
            ],
            'periods' => $this->periods($byMonth, $period),
            'products' => collect($products)
                ->map(fn (array $figures, string $name) => [...$figures, 'name' => $name, 'share' => $gross ? $figures['sales'] / $gross : 0.0])
                ->sort(fn (array $a, array $b) => [$a['name'] === self::OTHERS, $b['sales']] <=> [$b['name'] === self::OTHERS, $a['sales']])
                ->values()->all(),
        ];
    }

    /**
     * @param  Collection<string, array<string, int|float>>|Collection<int, array<string, mixed>>  $rows
     * @return array<string, int|float|null>
     */
    private function summed(Collection $rows): array
    {
        $sums = [];
        foreach (self::SUMMED as $key) {
            $sums[$key] = $rows->sum($key);
        }

        return [
            ...$sums,
            // Churned isn't limited by page, so customers still waiting for their page check count here too.
            'net' => $sums['added'] + $sums['added_waiting'] - $sums['all_lost'],
            'churn_rate' => self::rate($sums['all_lost'], $sums['all_due']),
            'fsd_churn_rate' => self::rate($sums['fsd_lost'], $sums['fsd_due']),
            'crd_churn_rate' => self::rate($sums['crd_lost'], $sums['crd_due']),
            'retained_rate' => self::rate($sums['retained'], $sums['retained_base']),
            'repeat_rate' => self::rate($sums['repeat'], $sums['repeat_base']),
            'aov' => $sums['orders'] ? $sums['gross'] / $sums['orders'] : null,
        ];
    }

    /**
     * The rates of the whole range: each team's churned ÷ due added over its complete months (every month when none is complete).
     *
     * @param  Collection<int, array<string, mixed>>  $months
     * @return array{churn_rate: ?float, fsd_churn_rate: ?float, crd_churn_rate: ?float, churn_due: int, churn_lost: int, churn_label: string}
     */
    private function overallChurn(Collection $months): array
    {
        $complete = $months->reject(fn (array $month) => $month['churn_partial']);
        $counted = $complete->isEmpty() ? $months : $complete;

        return [
            'churn_rate' => self::rate($counted->sum('all_lost'), $counted->sum('all_due')),
            'fsd_churn_rate' => self::rate($counted->sum('fsd_lost'), $counted->sum('fsd_due')),
            'crd_churn_rate' => self::rate($counted->sum('crd_lost'), $counted->sum('crd_due')),
            'churn_due' => $counted->sum('all_due'),
            'churn_lost' => $counted->sum('all_lost'),
            'churn_label' => $counted->count() === 1 ? $counted->first()['label'] : $counted->first()['label'].'–'.$counted->last()['label'],
        ];
    }

    private static function rate(int|float $part, int|float $of): ?float
    {
        return $of ? $part / $of : null;
    }

    /**
     * @param  Collection<string, Collection<string, array<string, int|float>>>  $byMonth
     * @return list<array<string, mixed>>
     */
    private function periods(Collection $byMonth, string $period): array
    {
        $rows = [];

        foreach ($byMonth as $key => $days) {
            $month = CarbonImmutable::parse($key.'-01');

            if ($period === 'monthly') {
                $rows[] = ['label' => $month->format('M Y'), ...$this->summed($days)];
            } elseif ($period === 'daily') {
                foreach ($days as $date => $day) {
                    $rows[] = ['label' => CarbonImmutable::parse($date)->format('M j'), ...$this->summed(collect([$day]))];
                }
            } else {
                foreach (MonthWeeks::for($month) as $week) {
                    $inWeek = $days->filter(fn (array $day, string $date) => $date >= $week['start']->toDateString() && $date <= $week['end']->toDateString());
                    if ($inWeek->isNotEmpty()) {
                        $rows[] = ['label' => $week['label'], ...$this->summed($inWeek)];
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * Leads and customers added, from every delivery on record.
     *
     * @param  array<string, array<string, int|float>>  $days
     */
    private function addLeads(array &$days, CarbonImmutable $yearStart, CarbonImmutable $today): void
    {
        $first = $today->toDateString();
        $yearFrom = $yearStart->toDateString();
        $handled = $this->firstHandledByCra();
        $accounts = PancakeOrder::crdAccounts();
        $pages = PancakePage::whereNotNull('page_id')->get(['page_id', 'counts_from'])
            ->mapWithKeys(fn (PancakePage $page) => [(string) $page->page_id => $page->counts_from?->toDateString()])->all();
        $completeMonths = $this->backfill->completeMonths();
        $products = [];
        $product = function (string $name, string $on) use (&$products) {
            return $products[$name.'|'.$on] ??= $this->catalog->match($name, $on) ?? false;
        };
        $seen = [];
        $leads = [];
        $firstDelivery = [];

        $consider = function (object $row, string $team, string $name) use (&$seen, &$leads, &$firstDelivery, $product, $pages, $completeMonths, $handled, $yearFrom, $first) {
            if (isset($seen[$row->order_id])) {
                return;
            }
            $seen[$row->order_id] = true;
            $delivered = substr((string) $row->delivered_date, 0, 10);
            $listed = $product($name, $delivered);

            if (! $listed || ! $listed->consumption_days) {
                return;
            }

            $customer = (string) ($row->customer_key ?: 'order:'.$row->order_id);
            $orderedOn = substr((string) ($row->ordered_on ?? $delivered), 0, 10);
            $page = $row->page_id === null || $row->page_id === ''
                ? (isset($completeMonths[substr($orderedOn, 0, 7)]) || $orderedOn > PancakePageBackfill::LAST_MONTH.'-31' ? 'other' : 'waiting')
                : (array_key_exists((string) $row->page_id, $pages) && ($pages[(string) $row->page_id] === null || $pages[(string) $row->page_id] <= $orderedOn) ? 'listed' : 'other');

            if (! isset($firstDelivery[$customer]) || $delivered < $firstDelivery[$customer][0]) {
                $firstDelivery[$customer] = [$delivered, $team, $page];
            }
            if ($page === 'other') {
                return;
            }

            $qty = $row->qty ?? $this->qtyFromItems($row->items ?? null, $listed, $product, $orderedOn);
            $ranOut = gmdate('Y-m-d', strtotime($delivered.' 00:00:00 UTC') + (max(1, (int) $qty) * $listed->consumption_days - 1) * 86400);

            if ($ranOut < $yearFrom || $ranOut > $first) {
                return;
            }
            if ($team === LogisticsOrder::TEAM_FSD && isset($handled[$customer]) && $handled[$customer] < $ranOut) {
                return;
            }

            $slot = $team.'_'.$page;
            $month = substr($ranOut, 0, 7);
            if (! isset($leads[$month][$customer][$slot]) || $ranOut < $leads[$month][$customer][$slot]) {
                $leads[$month][$customer][$slot] = $ranOut;
            }
        };

        DB::table('logistics_orders')
            ->leftJoin('pancake_orders', 'pancake_orders.pancake_order_id', '=', 'logistics_orders.order_id')
            ->select([
                'logistics_orders.id', 'logistics_orders.order_id', 'logistics_orders.team', 'logistics_orders.product', 'logistics_orders.qty', 'logistics_orders.delivered_date',
                'logistics_orders.phone_key as customer_key', 'pancake_orders.items', 'pancake_orders.page_id', 'pancake_orders.seller_name', 'pancake_orders.ordered_on',
            ])
            ->chunkById(5000, function (Collection $rows) use ($consider, $accounts) {
                foreach ($rows as $row) {
                    $consider($row, self::teamOf($row->seller_name, $row->team, $accounts), (string) $row->product);
                }
            }, 'logistics_orders.id', 'id');

        DB::table('delivered_orders')
            ->leftJoin('pancake_orders', 'pancake_orders.pancake_order_id', '=', 'delivered_orders.order_id')
            ->where('delivered_orders.delivered_date', '<', LogisticsOrder::coveredFrom())
            ->select([
                'delivered_orders.id', 'delivered_orders.order_id', 'delivered_orders.product_raw', 'delivered_orders.qty', 'delivered_orders.delivered_date', 'delivered_orders.phone_number',
                'pancake_orders.phone_key as customer_key', 'pancake_orders.page_id', 'pancake_orders.seller_name', 'pancake_orders.ordered_on',
            ])
            ->chunkById(5000, function (Collection $rows) use ($consider, $accounts) {
                foreach ($rows as $row) {
                    $row->customer_key = $row->customer_key ?: LeadGenerator::normalizePhone((string) $row->phone_number);
                    $consider($row, self::teamOf($row->seller_name, LogisticsOrder::TEAM_FSD, $accounts), (string) $row->product_raw);
                }
            }, 'delivered_orders.id', 'id');

        foreach ($leads as $customers) {
            foreach ($customers as $slots) {
                // One customer, one lead per month: confirmed pages first, then CRD over FSD.
                foreach (['crd_listed' => 'crd_leads', 'fsd_listed' => 'fsd_leads', 'crd_waiting' => 'crd_waiting', 'fsd_waiting' => 'fsd_waiting'] as $slot => $metric) {
                    if (isset($slots[$slot])) {
                        $days[$slots[$slot]][$metric]++;
                        break;
                    }
                }
            }
        }

        foreach ($firstDelivery as [$delivered, $team, $page]) {
            if ($team === LogisticsOrder::TEAM_FSD && isset($days[$delivered]) && $page !== 'other') {
                $days[$delivered][$page === 'listed' ? 'added' : 'added_waiting']++;
            }
        }
    }

    /**
     * CRD = sold by an account on the CRD team list; an order Pancake doesn't have keeps its delivery's team.
     *
     * @param  list<string>  $accounts
     */
    private static function teamOf(?string $seller, string $deliveryTeam, array $accounts): string
    {
        if ($seller === null) {
            return $deliveryTeam;
        }

        return PancakeOrder::isCrdAccount($seller, $accounts) ? LogisticsOrder::TEAM_CRD : LogisticsOrder::TEAM_FSD;
    }

    /**
     * A delivery without a quantity: the order's items of that product.
     */
    private function qtyFromItems(?string $items, object $listed, callable $product, string $on): int
    {
        $qty = 0;
        foreach (json_decode((string) $items, true) ?: [] as $item) {
            $match = $product((string) ($item['name'] ?? ''), $on);
            if ($match && $match->is($listed)) {
                $qty += (int) ($item['qty'] ?? 0);
            }
        }

        return $qty ?: 1;
    }

    /**
     * Each customer's first day handled by a CRA: their earliest order from a CRD account or lead assigned to a CRA.
     *
     * @return array<string, string>
     */
    private function firstHandledByCra(): array
    {
        $first = [];

        DB::table('pancake_orders')->whereIn('seller_name', PancakeOrder::crdAccounts())->whereNotNull('phone_key')
            ->selectRaw('phone_key, min(ordered_on) as first_on')->groupBy('phone_key')->get()
            ->each(function (object $row) use (&$first) {
                $first[$row->phone_key] = substr((string) $row->first_on, 0, 10);
            });

        foreach (DB::table('leads')->whereNotNull('assigned_to')->whereNotNull('assigned_at')->select('phone_number', 'assigned_at')->cursor() as $lead) {
            $customer = LeadGenerator::normalizePhone((string) $lead->phone_number);
            $day = substr((string) $lead->assigned_at, 0, 10);
            if ($customer !== '' && (! isset($first[$customer]) || $day < $first[$customer])) {
                $first[$customer] = $day;
            }
        }

        return $first;
    }

    /**
     * Retained and Repeat for one month, as the Dashboard's tiles show that month.
     *
     * @param  array<string, array<string, int|float>>  $days
     * @param  Collection<int, User>  $cras
     */
    private function addRetention(array &$days, Collection $cras, CarbonImmutable $from, CarbonImmutable $to): string
    {
        if (DeliveredRetention::covers($to)) {
            foreach ($this->deliveredRetention->perDay($from, $to) as $day => $counts) {
                foreach ($counts as $metric => $count) {
                    $days[$day][$metric] += $count;
                }
            }

            return DeliveredRetention::SOURCE;
        }

        foreach (['retained', 'repeat'] as $segment) {
            foreach ($this->stats->repeatPurchases($cras, $segment, $from, $to) as $lead) {
                $days[$lead->repeat_purchase_on->toDateString()][$segment]++;
            }
        }

        $leadFrom = WorkingDate::leadDayFor($from);
        $leadTo = WorkingDate::leadDayFor($to);
        $lag = WorkingDate::lagDays();
        $metricOf = [Lead::TYPE_FSD => 'retained_base', Lead::TYPE_CRD => 'repeat_base'];
        $ids = $cras->pluck('id');
        $add = function (string $leadDay, string $type, int $count) use (&$days, $lag, $metricOf, $from, $to) {
            // On the real day paired with the lead day; a month's first and last days keep to the month.
            $real = CarbonImmutable::parse($leadDay)->addDays($lag)->max($from)->min($to)->toDateString();
            if (isset($metricOf[$type], $days[$real])) {
                $days[$real][$metricOf[$type]] += $count;
            }
        };

        Lead::whereIn('assigned_to', $ids)->whereDate('listed_on', '>=', $leadFrom)->whereDate('listed_on', '<=', $leadTo)
            ->selectRaw('lead_type, date(listed_on) as day, count(*) as total')->groupBy('lead_type', 'day')->toBase()->get()
            ->each(fn (object $row) => $add(substr((string) $row->day, 0, 10), $row->lead_type, (int) $row->total));
        LeadMove::departed($leadFrom, $leadTo, $ids)
            ->selectRaw('leads.lead_type, date(lead_moves.listed_on) as day, count(*) as total')->groupBy('leads.lead_type', 'day')->toBase()->get()
            ->each(fn (object $row) => $add(substr((string) $row->day, 0, 10), $row->lead_type, (int) $row->total));

        return 'tracker';
    }

    /**
     * The Dashboard's Conversion rate tabs for one month; null before the tracker recorded purchases.
     *
     * @param  Collection<int, User>  $cras
     * @return array<string, array{customers: int, base: int}>|null
     */
    private function conversion(Collection $cras, CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        if (DeliveredRetention::covers($to)) {
            return null;
        }

        $daily = $this->goals->for($cras, new DashboardRange($from, $to, $from, false))['daily'];

        return collect($this->stats->conversion($cras, $from, $to, $daily))
            ->map(fn (array $tab) => ['customers' => $tab['customers'], 'base' => $tab['base']])
            ->all();
    }

    /**
     * @param  array<string, array<string, int|float>>  $days
     */
    private function addChurn(array &$days, CarbonImmutable $from, CarbonImmutable $to): void
    {
        foreach ($this->churn->perDeadline($from, $to) as $list => $deadlines) {
            foreach ($deadlines as $day => $counts) {
                if (isset($days[$day])) {
                    $days[$day]["{$list}_due"] += $counts['customers'];
                    $days[$day]["{$list}_lost"] += $counts['lost'];
                    $days[$day]["{$list}_reactivation"] += $counts['reactivation'];
                }
            }
        }
    }

    /**
     * Every CRA's gross sales and confirmed orders, and the same orders per product.
     *
     * @param  array<string, array<string, int|float>>  $days
     * @param  array<string, array<string, mixed>>  $months
     * @param  Collection<int, User>  $cras
     */
    private function addSales(array &$days, array &$months, Collection $cras, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $accounts = ConversionBreakdown::accounts($cras);

        if ($accounts->isEmpty()) {
            return;
        }

        foreach (ConversionBreakdown::taggedOrders($accounts, $from, $to)->cursor() as $order) {
            $day = $order->ordered_on->toDateString();
            $month = substr($day, 0, 7);
            $counted = $order->isCounted();
            $days[$day]['gross'] += $order->sales();
            $days[$day]['shecom_gross'] += (float) ($order->shecom_sales ?? 0);
            $days[$day]['orders'] += $counted ? 1 : 0;

            $names = [];
            foreach ($this->productSales->split($order) as $item) {
                $name = $item['listed'] ? $item['name'] : self::OTHERS;
                $months[$month]['products'][$name] ??= ['sales' => 0.0, 'orders' => 0, 'qty' => 0];
                $months[$month]['products'][$name]['sales'] += $item['sales'];
                $months[$month]['products'][$name]['qty'] += $counted ? $item['qty'] : 0;
                if ($counted && ! isset($names[$name])) {
                    $names[$name] = true;
                    $months[$month]['products'][$name]['orders']++;
                }
            }
        }
    }
}
