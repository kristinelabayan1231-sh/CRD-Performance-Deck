<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Dashboard numbers for the Segmentation Tracker, for a range of lead days.
 *
 * Processed = status set. Unprocessed = no status yet.
 * Converted = Repeat Purchase "yes", or "no" with feedback PURCHASED.
 * Retained = converted CRD (returning) leads; FSD converted = converted FSD leads.
 */
class SegmentationStats
{
    private const HOT = ['hot', 'canpro_hot'];

    private const WARM = ['warm', 'canpro_warm'];

    private const COLD = ['cold', 'canpro_cold'];

    /**
     * Lead days $from–$to, compared with the same number of days just before. A single day's
     * trend line shows the week up to it.
     *
     * @param  Collection<int, User>  $cras  CRAs included (a CRA sees only themselves)
     */
    public function range(Collection $cras, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = (int) $from->diffInDays($to) + 1;

        return $this->period('Range', $from, $to, $from->subDays($days), $from->subDay(), $cras, $to,
            chartFrom: $days === 1 ? $from->subDays(6) : null);
    }

    private function period(
        string $name, CarbonImmutable $from, CarbonImmutable $to,
        CarbonImmutable $prevFrom, CarbonImmutable $prevTo,
        Collection $cras, CarbonImmutable $today, ?CarbonImmutable $chartFrom = null,
    ): array {
        $ids = $cras->pluck('id');
        $now = $this->totals($ids, $from, $to);
        $before = $this->totals($ids, $prevFrom, $prevTo);

        $pct = fn (array $t, string $key) => $t['leads'] ? $t[$key] / $t['leads'] * 100 : 0.0;

        return [
            'name' => $name,
            'label' => $from->equalTo($to) ? $from->format('M j') : $from->format('M j').'–'.($from->isSameMonth($to) ? $to->format('j') : $to->format('M j')),
            'kpis' => [
                $this->kpi('Leads', $now['leads'], $before['leads'], 'count', higherIsBetter: true),
                $this->kpi('Catered', $pct($now, 'processed'), $pct($before, 'processed'), 'percent', higherIsBetter: true),
                $this->kpi('Converted', $pct($now, 'converted'), $pct($before, 'converted'), 'percent', higherIsBetter: true),
                // Cold = leads tagged Cold / CanPro Cold, shown as a share and as "cold/leads".
                $this->kpi('Went cold', $pct($now, 'cold'), $pct($before, 'cold'), 'percent', higherIsBetter: false,
                    count: number_format($now['cold']).'/'.number_format($now['leads'])),
            ],
            'retained' => $now['crd'] ? round($now['crd_converted'] / $now['crd'] * 100) : 0,
            'fsd_converted' => $now['fsd'] ? round($now['fsd_converted'] / $now['fsd'] * 100) : 0,
            'series' => $this->series($ids, $chartFrom ?? $from, $to, $today),
            'tags' => [
                ['label' => 'Hot', 'value' => $now['hot'], 'color' => '#E0663F'],
                ['label' => 'Cold', 'value' => $now['cold'], 'color' => '#2F6FD6'],
                ['label' => 'Warm', 'value' => $now['warm'], 'color' => '#C9970E'],
                ['label' => 'High value', 'value' => $now['high_value'], 'color' => '#8B3FF0'],
            ],
            'untagged' => $now['leads'] - $now['hot'] - $now['cold'] - $now['warm'] - $now['high_value'],
            'leads' => $now['leads'],
            'ranking' => $this->ranking($cras, $from, $to),
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
            ->selectRaw('sum(case when status is not null then 1 else 0 end) as processed')
            ->selectRaw('sum(case when customer_tag in (?, ?) then 1 else 0 end) as hot', self::HOT)
            ->selectRaw('sum(case when customer_tag in (?, ?) then 1 else 0 end) as warm', self::WARM)
            ->selectRaw('sum(case when customer_tag in (?, ?) then 1 else 0 end) as cold', self::COLD)
            ->selectRaw("sum(case when customer_tag = 'high_value' then 1 else 0 end) as high_value")
            ->selectRaw('sum(case when lead_type = ? then 1 else 0 end) as crd', [Lead::TYPE_CRD])
            ->selectRaw('sum(case when lead_type = ? then 1 else 0 end) as fsd', [Lead::TYPE_FSD])
            ->first();

        $c = $this->scope($ids, $from, $to)->converted()
            ->selectRaw('count(*) as converted')
            ->selectRaw('sum(case when lead_type = ? then 1 else 0 end) as crd_converted', [Lead::TYPE_CRD])
            ->selectRaw('sum(case when lead_type = ? then 1 else 0 end) as fsd_converted', [Lead::TYPE_FSD])
            ->first();

        return collect([...$t->getAttributes(), ...$c->getAttributes()])->map(fn ($v) => (int) ($v ?? 0))->all();
    }

    /**
     * One KPI card: value, previous value, and the change between them.
     * Counts change in %, percentages change in points. $count is an optional
     * "part/total" shown beside a percentage.
     */
    private function kpi(string $label, float $value, float $previous, string $format, bool $higherIsBetter, ?string $count = null): array
    {
        $delta = $format === 'count'
            ? ($previous ? ($value - $previous) / $previous * 100 : null)
            : $value - $previous;
        $direction = $delta === null || round($delta) == 0 ? 'flat' : ($delta > 0 ? 'up' : 'down');

        return [
            'label' => $label,
            'value' => $format === 'count' ? number_format($value) : round($value).'%',
            'previous' => $format === 'count' ? number_format($previous) : round($previous).'%',
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
            ->selectRaw('date(est_out_of_stock_date) as day, count(*) as leads, sum(case when status is not null then 1 else 0 end) as processed')
            ->groupBy('day')
            ->get()
            ->keyBy(fn ($r) => CarbonImmutable::parse($r->day)->toDateString());

        $points = [];
        for ($day = $from; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            $r = $rows[$day->toDateString()] ?? null;
            $processed = (int) ($r->processed ?? 0);
            $points[] = [
                'day' => $day->toDateString(),
                'label' => $day->format('M j'),
                'processed' => $processed,
                'unprocessed' => (int) ($r->leads ?? 0) - $processed,
            ];
        }

        return $points;
    }

    /**
     * Top 5 CRAs by processed leads.
     *
     * @return list<array{name: string, processed: int, unprocessed: int, converted: int}>
     */
    private function ranking(Collection $cras, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ids = $cras->pluck('id');
        $rows = $this->scope($ids, $from, $to)
            ->selectRaw('assigned_to, count(*) as leads, sum(case when status is not null then 1 else 0 end) as processed')
            ->groupBy('assigned_to')->get()->keyBy('assigned_to');
        $converted = $this->scope($ids, $from, $to)->converted()
            ->selectRaw('assigned_to, count(*) as n')->groupBy('assigned_to')->pluck('n', 'assigned_to');

        return $cras->map(function (User $cra) use ($rows, $converted) {
            $leads = (int) ($rows[$cra->id]->leads ?? 0);
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
            ->whereDate('est_out_of_stock_date', '>=', $from)
            ->whereDate('est_out_of_stock_date', '<=', $to);
    }
}
