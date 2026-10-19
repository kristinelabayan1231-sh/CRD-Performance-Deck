<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadMove;
use App\Models\PancakeOrder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Dashboard numbers for the Segmentation Tracker, for a range of lead days.
 *
 * Processed (Catered) = as the tracker's Catered list: a status, a contact date, or marked as processed. Unprocessed = none of these.
 * Converted = Retained + Repeat, as the dashboard's tiles count them: Repeat Purchase = Yes bought on the real days
 * paired with the lead days, one per customer; Retained = FSD Leads (of the FSD leads), Repeat = CRD Leads (of the CRD leads).
 */
class SegmentationStats
{
    private const HOT = ['hot', 'canpro_hot'];

    private const WARM = ['warm', 'canpro_warm'];

    private const COLD = ['cold', 'canpro_cold'];

    /** Catered, as the tracker's Catered list: a status, a contact date, or marked as processed (Lead::processed()). */
    private const CATERED = 'status is not null or contact_date is not null or processed_at is not null';

    /**
     * Lead days $from–$to, compared with the same number of days just before. A single day's
     * trend line shows the week up to it. Purchases are by the real days $boughtFrom–$boughtTo paired with
     * them (Settings → Working Date); the lead days themselves when not given.
     *
     * @param  Collection<int, User>  $cras  CRAs included (a CRA sees only themselves)
     */
    public function range(Collection $cras, CarbonImmutable $from, CarbonImmutable $to, ?CarbonImmutable $boughtFrom = null, ?CarbonImmutable $boughtTo = null): array
    {
        $days = (int) $from->diffInDays($to) + 1;
        $boughtFrom ??= $from;
        $boughtTo ??= $to;

        return $this->period('Range', $from, $to, $from->subDays($days), $from->subDay(), $cras, $to,
            [$boughtFrom, $boughtTo], [$boughtFrom->subDays($days), $boughtFrom->subDay()],
            chartFrom: $days === 1 ? $from->subDays(6) : null);
    }

    /**
     * The dashboard's Retained and Repeat tiles, from the Segmentation Tracker, one per customer (contact number):
     * Retained = FSD Leads and Repeat = CRD Leads with Repeat Purchase = Yes bought in $from–$to (the day it
     * was set to Yes, whatever the lead day). Each out of its own leads: the tracker's FSD Leads (Retained) or CRD Leads
     * (Repeat) on lead days $leadFrom–$leadTo.
     *
     * @param  Collection<int, User>  $cras  CRAs included (a CRA sees only themselves)
     * @return array{leads: array{retained: int, repeat: int}, retained: int, repeat: int}
     */
    public function customers(Collection $cras, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $leadFrom, CarbonImmutable $leadTo): array
    {
        $departed = $this->departedByType($cras->pluck('id'), $leadFrom, $leadTo);

        return [
            'leads' => [
                'retained' => $this->scope($cras->pluck('id'), $leadFrom, $leadTo)->where('lead_type', Lead::TYPE_FSD)->count() + ($departed[Lead::TYPE_FSD] ?? 0),
                'repeat' => $this->scope($cras->pluck('id'), $leadFrom, $leadTo)->where('lead_type', Lead::TYPE_CRD)->count() + ($departed[Lead::TYPE_CRD] ?? 0),
            ],
            'retained' => $this->repeatPurchases($cras, 'retained', $from, $to)->count(),
            'repeat' => $this->repeatPurchases($cras, 'repeat', $from, $to)->count(),
        ];
    }

    /**
     * The dashboard's Conversion rate tabs, all counting customers (one each):
     * - sc: Retained + Repeat customers (the Segmentation Tracker's Converted) ÷ tracker leads;
     * - bc: customers with a Broadcast order (as Total confirmed orders counts them) ÷ Pancake engagements;
     * - all: every customer who bought, once (Converted, plus Broadcast buyers not among them) ÷ (engagements + leads).
     * Per day too, from $daily (SalesGoalProgress' days: engagements and the paired lead day's leads).
     *
     * @param  Collection<int, User>  $cras
     * @param  array<string, array{engagements: int, leads: int}>  $daily
     * @return array<string, array{customers: int, base: int, rate: ?float, daily: array<string, ?float>}>
     */
    public function conversion(Collection $cras, CarbonImmutable $from, CarbonImmutable $to, array $daily): array
    {
        $phone = fn (?string $number) => LeadGenerator::normalizePhone((string) $number);
        $converted = $this->repeatPurchases($cras, 'retained', $from, $to)->concat($this->repeatPurchases($cras, 'repeat', $from, $to));
        $convertedPhones = $converted->map(fn (Lead $lead) => $phone($lead->phone_number))->flip();

        // Broadcast buyers, one per number (an order with no number is its own customer).
        $broadcast = ConversionBreakdown::taggedOrders(ConversionBreakdown::accounts($cras), $from, $to)->get()
            ->filter(fn (PancakeOrder $order) => $order->isCounted() && $order->counted_type === PancakeOrder::BROADCAST)
            ->map(fn (PancakeOrder $order) => [
                'customer' => $order->phone_key ? $phone($order->phone_key) : 'order:'.$order->pancake_order_id,
                'day' => $order->ordered_on->toDateString(),
            ]);
        $bcCustomers = $broadcast->pluck('customer')->unique();
        $bcOnly = $bcCustomers->reject(fn (string $customer) => isset($convertedPhones[$customer]));

        $leads = (int) array_sum(array_column($daily, 'leads'));
        $engagements = (int) array_sum(array_column($daily, 'engagements'));
        $rate = fn (int $part, int $of) => $of ? $part / $of : null;

        $scDay = $converted->countBy(fn (Lead $lead) => $lead->repeat_purchase_on->toDateString());
        $bcDay = $broadcast->unique(fn (array $sale) => $sale['day'].'|'.$sale['customer'])->countBy('day');
        $bcOnlyDay = $broadcast->reject(fn (array $sale) => isset($convertedPhones[$sale['customer']]))
            ->unique(fn (array $sale) => $sale['day'].'|'.$sale['customer'])->countBy('day');
        $perDay = fn (callable $value) => collect($daily)->map(fn (array $day, string $key) => $value($day, $key))->all();

        return [
            'all' => [
                'customers' => $converted->count() + $bcOnly->count(), 'base' => $engagements + $leads,
                'rate' => $rate($converted->count() + $bcOnly->count(), $engagements + $leads),
                'daily' => $perDay(fn ($day, $key) => $rate(($scDay[$key] ?? 0) + ($bcOnlyDay[$key] ?? 0), $day['engagements'] + $day['leads'])),
            ],
            'sc' => [
                'customers' => $converted->count(), 'base' => $leads, 'rate' => $rate($converted->count(), $leads),
                'daily' => $perDay(fn ($day, $key) => $rate($scDay[$key] ?? 0, $day['leads'])),
            ],
            'bc' => [
                'customers' => $bcCustomers->count(), 'base' => $engagements, 'rate' => $rate($bcCustomers->count(), $engagements),
                'daily' => $perDay(fn ($day, $key) => $rate($bcDay[$key] ?? 0, $day['engagements'])),
            ],
        ];
    }

    /**
     * The customers behind a Retained ('retained': FSD Leads) or Repeat ('repeat': CRD Leads) tile: leads with Repeat
     * Purchase = Yes bought in $from–$to, one per contact number (their first purchase in the dates), first bought first.
     *
     * @param  Collection<int, User>  $cras
     * @return Collection<int, Lead>
     */
    public function repeatPurchases(Collection $cras, string $segment, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Lead::whereIn('assigned_to', $cras->pluck('id'))
            ->where('lead_type', $segment === 'retained' ? Lead::TYPE_FSD : Lead::TYPE_CRD)
            ->repeatPurchasedBetween($from, $to)
            ->with('assignee')
            ->orderBy('repeat_purchase_on')->orderBy('customer_name')->orderBy('id')
            ->get()
            ->unique(fn (Lead $lead) => LeadGenerator::normalizePhone((string) $lead->phone_number))
            ->values();
    }

    private function period(
        string $name, CarbonImmutable $from, CarbonImmutable $to,
        CarbonImmutable $prevFrom, CarbonImmutable $prevTo,
        Collection $cras, CarbonImmutable $today, array $bought, array $prevBought, ?CarbonImmutable $chartFrom = null,
    ): array {
        $ids = $cras->pluck('id');
        $now = [...$this->totals($ids, $from, $to), ...$this->bought($cras, ...$bought)];
        $before = [...$this->totals($ids, $prevFrom, $prevTo), ...$this->bought($cras, ...$prevBought)];
        $share = fn (int $part, int $of) => $of ? round($part / $of * 100, 1) : 0.0;

        $pct = fn (array $t, string $key) => $t['leads'] ? $t[$key] / $t['leads'] * 100 : 0.0;

        return [
            'name' => $name,
            'label' => $from->equalTo($to) ? $from->format('M j') : $from->format('M j').'–'.($from->isSameMonth($to) ? $to->format('j') : $to->format('M j')),
            'kpis' => [
                $this->kpi('Leads', $now['leads'], $before['leads'], 'count', higherIsBetter: true),
                $this->kpi('Catered', $pct($now, 'processed'), $pct($before, 'processed'), 'percent', higherIsBetter: true),
                $this->kpi('Converted', $pct($now, 'converted'), $pct($before, 'converted'), 'percent', higherIsBetter: true,
                    // One decimal, as the Retained and Repeat tiles.
                    count: number_format($now['converted']).'/'.number_format($now['leads']), decimals: 1),
                // Cold = leads tagged Cold / CanPro Cold, shown as a share and as "cold/leads".
                $this->kpi('Went cold', $pct($now, 'cold'), $pct($before, 'cold'), 'percent', higherIsBetter: false,
                    count: number_format($now['cold']).'/'.number_format($now['leads'])),
            ],
            // Same as the dashboard's Retained and Repeat tiles.
            'retained' => $share($now['retained'], $now['fsd']),
            'repeat' => $share($now['repeat'], $now['crd']),
            // Counts behind them, so Converted visibly adds up: Retained + Repeat out of FSD + CRD leads.
            'counts' => ['retained' => $now['retained'], 'fsd' => $now['fsd'], 'repeat' => $now['repeat'], 'crd' => $now['crd']],
            'series' => $this->series($ids, $chartFrom ?? $from, $to, $today),
            'tags' => [
                ['label' => 'Hot', 'value' => $now['hot'], 'color' => '#E0663F'],
                ['label' => 'Cold', 'value' => $now['cold'], 'color' => '#2F6FD6'],
                ['label' => 'Warm', 'value' => $now['warm'], 'color' => '#C9970E'],
                ['label' => 'High value', 'value' => $now['high_value'], 'color' => '#8B3FF0'],
            ],
            'untagged' => $now['leads'] - $now['hot'] - $now['cold'] - $now['warm'] - $now['high_value'],
            'leads' => $now['leads'],
            'ranking' => $this->ranking($cras, $from, $to, $now['by_cra']),
        ];
    }

    /**
     * @param  Collection<int, int>  $ids
     * @return array<string, int>
     */
    private function totals(Collection $ids, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $t = $this->scope($ids, $from, $to)
            ->selectRaw('count(*) as leads')
            ->selectRaw('sum(case when '.self::CATERED.' then 1 else 0 end) as processed')
            ->selectRaw('sum(case when customer_tag in (?, ?) then 1 else 0 end) as hot', self::HOT)
            ->selectRaw('sum(case when customer_tag in (?, ?) then 1 else 0 end) as warm', self::WARM)
            ->selectRaw('sum(case when customer_tag in (?, ?) then 1 else 0 end) as cold', self::COLD)
            ->selectRaw("sum(case when customer_tag = 'high_value' then 1 else 0 end) as high_value")
            ->selectRaw('sum(case when lead_type = ? then 1 else 0 end) as crd', [Lead::TYPE_CRD])
            ->selectRaw('sum(case when lead_type = ? then 1 else 0 end) as fsd', [Lead::TYPE_FSD])
            ->first();
        $totals = collect($t->getAttributes())->map(fn ($v) => (int) ($v ?? 0))->all();

        // Leads moved off these days (backlog fill, transfers) still count there, as listed but not catered.
        foreach ($this->departedByType($ids, $from, $to) as $type => $count) {
            $totals['leads'] += $count;
            $totals[$type] += $count;
        }

        return $totals;
    }

    /**
     * Leads moved off lead days $from–$to for these CRAs, per lead type.
     *
     * @return array<string, int>
     */
    private function departedByType(Collection $ids, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return LeadMove::departed($from, $to, $ids)
            ->selectRaw('leads.lead_type, count(*) as total')->groupBy('leads.lead_type')
            ->toBase()->pluck('total', 'lead_type')->map(fn ($n) => (int) $n)
            ->only([Lead::TYPE_CRD, Lead::TYPE_FSD])->all();
    }

    /**
     * Retained and Repeat customers bought on real days $from–$to (as the dashboard's tiles), their sum
     * (converted), and the converted per CRA.
     *
     * @param  Collection<int, User>  $cras
     * @return array{retained: int, repeat: int, converted: int, by_cra: array<int, int>}
     */
    private function bought(Collection $cras, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $retained = $this->repeatPurchases($cras, 'retained', $from, $to);
        $repeat = $this->repeatPurchases($cras, 'repeat', $from, $to);

        return [
            'retained' => $retained->count(),
            'repeat' => $repeat->count(),
            'converted' => $retained->count() + $repeat->count(),
            'by_cra' => $retained->concat($repeat)->countBy('assigned_to')->all(),
        ];
    }

    /**
     * One KPI card: value, previous value, and the change between them.
     * Counts change in %, percentages change in points. $count is an optional
     * "part/total" shown beside a percentage; $decimals, the percentage's decimals.
     */
    private function kpi(string $label, float $value, float $previous, string $format, bool $higherIsBetter, ?string $count = null, int $decimals = 0): array
    {
        $delta = $format === 'count'
            ? ($previous ? ($value - $previous) / $previous * 100 : null)
            : $value - $previous;
        $direction = $delta === null || round($delta) == 0 ? 'flat' : ($delta > 0 ? 'up' : 'down');

        return [
            'label' => $label,
            'value' => $format === 'count' ? number_format($value) : number_format($value, $decimals).'%',
            'previous' => $format === 'count' ? number_format($previous) : number_format($previous, $decimals).'%',
            'change' => $delta === null ? '—' : (($delta > 0 ? '+' : '').round($delta).($format === 'count' ? '%' : ' pts')),
            'direction' => $direction,
            'good' => $direction === 'flat' ? null : (($direction === 'up') === $higherIsBetter),
            'count' => $count,
        ];
    }

    /**
     * Processed vs unprocessed per lead day, for the trend line. Days after
     * today are left out so the line stops at today.
     *
     * @return list<array{day: string, label: string, processed: int, unprocessed: int}>
     */
    private function series(Collection $ids, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $today): array
    {
        $end = $to->min($today);

        $rows = $this->scope($ids, $from, $end)
            ->selectRaw('date(listed_on) as day, count(*) as leads, sum(case when '.self::CATERED.' then 1 else 0 end) as processed')
            ->groupBy('day')
            ->get()
            ->keyBy(fn ($r) => CarbonImmutable::parse($r->day)->toDateString());
        // Leads the backlog fill moved off a day still count there, as unprocessed.
        $departed = collect(LeadMove::countsByCraAndDay(LeadMove::departed($from, $end, $ids)))
            ->reduce(function (array $sum, array $days) {
                foreach ($days as $day => $n) {
                    $sum[$day] = ($sum[$day] ?? 0) + $n;
                }

                return $sum;
            }, []);

        $points = [];
        for ($day = $from; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            $r = $rows[$day->toDateString()] ?? null;
            $processed = (int) ($r->processed ?? 0);
            $points[] = [
                'day' => $day->toDateString(),
                'label' => $day->format('M j'),
                'processed' => $processed,
                'unprocessed' => (int) ($r->leads ?? 0) + ($departed[$day->toDateString()] ?? 0) - $processed,
            ];
        }

        return $points;
    }

    /**
     * Top 5 CRAs by processed leads.
     *
     * @param  array<int, int>  $converted  Retained + Repeat customers per CRA
     * @return list<array{name: string, processed: int, unprocessed: int, converted: int}>
     */
    private function ranking(Collection $cras, CarbonImmutable $from, CarbonImmutable $to, array $converted): array
    {
        $rows = $this->scope($cras->pluck('id'), $from, $to)
            ->selectRaw('assigned_to, count(*) as leads, sum(case when '.self::CATERED.' then 1 else 0 end) as processed')
            ->groupBy('assigned_to')->get()->keyBy('assigned_to');
        $departed = LeadMove::departed($from, $to, $cras->pluck('id'))
            ->selectRaw('lead_moves.assigned_to as cra, count(*) as n')->groupBy('cra')->toBase()->pluck('n', 'cra');

        return $cras->map(function (User $cra) use ($rows, $converted, $departed) {
            $leads = (int) ($rows[$cra->id]->leads ?? 0) + (int) ($departed[$cra->id] ?? 0);
            $processed = (int) ($rows[$cra->id]->processed ?? 0);

            return [
                'name' => $cra->displayName(),
                'processed' => $processed,
                'unprocessed' => $leads - $processed,
                'converted' => (int) ($converted[$cra->id] ?? 0),
            ];
        })
            ->sort(fn ($a, $b) => [$b['processed'], $a['unprocessed'], $a['name']] <=> [$a['processed'], $b['unprocessed'], $b['name']])
            ->take(5)->values()->all();
    }

    private function scope(Collection $ids, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return Lead::whereIn('assigned_to', $ids)
            ->whereDate('listed_on', '>=', $from)
            ->whereDate('listed_on', '<=', $to);
    }
}
