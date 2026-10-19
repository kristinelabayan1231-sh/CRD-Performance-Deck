<?php

namespace App\Services;

use App\Models\BacklogHandout;
use App\Models\Lead;
use App\Models\LeadMove;
use App\Models\PancakeEngagement;
use App\Models\PancakeOrder;
use App\Models\User;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Segmentation Productivity numbers per CRA per day.
 *
 * Assigned transactions = Segmentation Tracker leads assigned to the CRA for that lead day (base 70; actual count shown).
 * Answered = tracker leads with that Contact Date + Pancake customer engagements of the CRA's Pancake account.
 * Total confirmed = the CRA's own Pancake orders that day tagged CRD - BROADCAST or CRD - SEGMENTATION (canceled and deleted don't count).
 * Of those, assigned lead conversion = the customer is on the CRA's assigned leads; Pancake conversion = everyone else. Conversion rate = confirmed ÷ answered. Pick-up rate = answered ÷ assigned.
 * Gross sales = Conversion Breakdown's gross: the CRA's orders tagged CRD - BROADCAST + CRD - SEGMENTATION. AOV = gross ÷ confirmed.
 */
class SegmentationProductivity
{
    public const COUNTS = ['assigned', 'calls', 'chat', 'answered', 'alc', 'pc', 'confirmed'];

    public function __construct(private ConversionBreakdown $breakdown) {}

    /**
     * @param  Collection<int, User>  $cras
     * @return array<int, array<string, array<string, int|float|null>>> cra id => Y-m-d => metrics
     */
    public function days(Collection $cras, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ids = $cras->pluck('id');
        $days = [];
        for ($day = $from->startOfDay(); $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $days[] = $day->toDateString();
        }

        // Leads worked on a real day are from its paired lead day (Settings → Working Date).
        $lag = WorkingDate::lagDays();
        $assigned = $this->grouped(
            Lead::whereIn('assigned_to', $ids)->withoutPosSales()
                ->whereDate('listed_on', '>=', $from->subDays($lag))->whereDate('listed_on', '<=', $to->subDays($lag))
                ->selectRaw('assigned_to as cra, date(listed_on) as day, count(*) as n')->groupBy('cra', 'day')->get(),
            $lag,
        );
        // Plus leads the backlog fill moved off those lead days.
        foreach (LeadMove::countsByCraAndDay(LeadMove::departed($from->subDays($lag), $to->subDays($lag), $ids)) as $cra => $perDay) {
            foreach ($perDay as $day => $n) {
                $real = CarbonImmutable::parse($day)->addDays($lag)->toDateString();
                $assigned[$cra][$real] = ($assigned[$cra][$real] ?? 0) + $n;
            }
        }

        $calls = $this->grouped(
            Lead::whereIn('assigned_to', $ids)->withoutPosSales()->whereDate('contact_date', '>=', $from)->whereDate('contact_date', '<=', $to)
                ->selectRaw('assigned_to as cra, date(contact_date) as day, count(*) as n')->groupBy('cra', 'day')->get(),
        );

        // Pancake account name => CRA id.
        $accounts = ConversionBreakdown::accounts($cras);

        $chat = [];
        PancakeEngagement::whereIn('staff_name', $accounts->keys())
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->get()
            ->each(function (PancakeEngagement $row) use (&$chat, $accounts) {
                $cra = $accounts[$row->staff_name];
                $chat[$cra][$row->date->toDateString()] = ($chat[$cra][$row->date->toDateString()] ?? 0) + $row->engagements;
            });

        [$alc, $pc] = $this->conversions($ids, $accounts, $from, $to);
        $tagged = $this->breakdown->orders($accounts, $from, $to);

        $result = [];
        foreach ($cras as $cra) {
            foreach ($days as $day) {
                $result[$cra->id][$day] = self::derive([
                    'assigned' => $assigned[$cra->id][$day] ?? 0,
                    'calls' => $calls[$cra->id][$day] ?? 0,
                    'chat' => $chat[$cra->id][$day] ?? 0,
                    'alc' => $alc[$cra->id][$day] ?? 0,
                    'pc' => $pc[$cra->id][$day] ?? 0,
                    'gross' => ($tagged[$cra->id][$day]['bc_gross'] ?? 0.0) + ($tagged[$cra->id][$day]['sc_gross'] ?? 0.0),
                ]);
            }
        }

        return $result;
    }

    /**
     * Backlog progress per CRA: older leads handed to them (BacklogHandout: daily fill or transfer) on the lead days
     * paired with real days $from–$to, how many they've catered since, and how many reordered (Repeat Purchase = Yes
     * bought on or after the real day it was handed out). A lead handed out more than once counts once, from its first day.
     *
     * @param  Collection<int, User>  $cras
     * @return array<int, array{handed: int, catered: int, reordered: int, progress: ?float, rate: ?float}>
     */
    public function backlog(Collection $cras, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $lag = WorkingDate::lagDays();
        $handouts = BacklogHandout::query()
            ->join('leads', 'leads.id', '=', 'backlog_handouts.lead_id')
            ->whereIn('backlog_handouts.user_id', $cras->pluck('id'))
            ->whereDate('backlog_handouts.handed_on', '>=', $from->subDays($lag))
            ->whereDate('backlog_handouts.handed_on', '<=', $to->subDays($lag))
            ->orderBy('backlog_handouts.handed_on')
            ->toBase()
            ->get(['backlog_handouts.user_id', 'backlog_handouts.lead_id', 'backlog_handouts.handed_on', 'backlog_handouts.created_at',
                'leads.status', 'leads.contact_date', 'leads.processed_at', 'leads.repeat_purchase', 'leads.repeat_purchase_on'])
            ->groupBy('user_id');

        return $cras->mapWithKeys(function (User $cra) use ($handouts) {
            $leads = ($handouts[$cra->id] ?? collect())->unique('lead_id');

            return [$cra->id => self::backlogRates([
                'handed' => $leads->count(),
                'catered' => $leads->filter(fn ($l) => $l->status !== null || $l->contact_date !== null || $l->processed_at !== null)->count(),
                // Purchases are on real dates, so compared with the real day it was handed out (not the lead day).
                'reordered' => $leads->filter(fn ($l) => $l->repeat_purchase === 'yes' && $l->repeat_purchase_on !== null
                    && substr((string) $l->repeat_purchase_on, 0, 10) >= CarbonImmutable::parse($l->created_at, 'UTC')->setTimezone(config('segmentation.timezone'))->toDateString())->count(),
            ])];
        })->all();
    }

    /**
     * Add up CRAs' backlog progress and work the rates out again.
     *
     * @param  iterable<array{handed: int, catered: int, reordered: int}>  $rows
     * @return array{handed: int, catered: int, reordered: int, progress: ?float, rate: ?float}
     */
    public static function sumBacklog(iterable $rows): array
    {
        $totals = ['handed' => 0, 'catered' => 0, 'reordered' => 0];

        foreach ($rows as $row) {
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $row[$key];
            }
        }

        return self::backlogRates($totals);
    }

    /**
     * @param  array{handed: int, catered: int, reordered: int}  $counts
     * @return array{handed: int, catered: int, reordered: int, progress: ?float, rate: ?float}
     */
    private static function backlogRates(array $counts): array
    {
        return [
            ...$counts,
            'progress' => $counts['handed'] ? $counts['catered'] / $counts['handed'] : null,
            'rate' => $counts['handed'] ? $counts['reordered'] / $counts['handed'] : null,
        ];
    }

    /**
     * Add up several days (or CRAs) and work the rates out again from the totals.
     *
     * @param  iterable<array<string, int|float|null>>  $rows
     * @return array<string, int|float|null>
     */
    public static function sum(iterable $rows): array
    {
        $totals = [...array_fill_keys(['assigned', 'calls', 'chat', 'alc', 'pc'], 0), 'gross' => 0.0];

        foreach ($rows as $row) {
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $row[$key];
            }
        }

        return self::derive($totals);
    }

    /**
     * @param  array{assigned: int, calls: int, chat: int, alc: int, pc: int, gross: float}  $base
     * @return array<string, int|float|null>
     */
    private static function derive(array $base): array
    {
        $answered = $base['calls'] + $base['chat'];
        $confirmed = $base['alc'] + $base['pc'];

        return [
            ...$base,
            'answered' => $answered,
            'confirmed' => $confirmed,
            'conversion_rate' => $answered ? $confirmed / $answered : null,
            'pickup_rate' => $base['assigned'] ? $answered / $base['assigned'] : null,
            'aov' => $confirmed ? $base['gross'] / $confirmed : null,
        ];
    }

    /**
     * Confirmed orders per CRA per day, split [assigned lead conversion, Pancake conversion]: the CRA's own
     * orders tagged CRD - BROADCAST or CRD - SEGMENTATION, by whether the customer is on that CRA's assigned leads.
     *
     * @param  Collection<int, int>  $ids
     * @param  Collection<string, int>  $accounts  Pancake account name => CRA id
     * @return array{0: array<int, array<string, int>>, 1: array<int, array<string, int>>}
     */
    private function conversions(Collection $ids, Collection $accounts, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($accounts->isEmpty()) {
            return [[], []];
        }

        $orders = PancakeOrder::counted()->crdProducts()
            ->whereIn('seller_name', $accounts->keys())
            ->whereIn('conversion_type', [PancakeOrder::BROADCAST, PancakeOrder::SEGMENTATION])
            ->where('ordered_on', '>=', $from->toDateString())->where('ordered_on', '<', $to->addDay()->toDateString())
            ->get(['ordered_on', 'seller_name', 'phone_key']);

        if ($orders->isEmpty()) {
            return [[], []];
        }

        // Each CRA's own leads, as "cra id|phone".
        $ownLeads = Lead::whereIn('assigned_to', $ids)->withoutPosSales()->get(['phone_number', 'assigned_to'])
            ->map(fn (Lead $lead) => $lead->assigned_to.'|'.LeadGenerator::normalizePhone($lead->phone_number))
            ->flip();

        $alc = [];
        $pc = [];

        foreach ($orders as $order) {
            $cra = $accounts[$order->seller_name];
            $day = $order->ordered_on->toDateString();

            if ($order->phone_key && isset($ownLeads[$cra.'|'.$order->phone_key])) {
                $alc[$cra][$day] = ($alc[$cra][$day] ?? 0) + 1;
            } else {
                $pc[$cra][$day] = ($pc[$cra][$day] ?? 0) + 1;
            }
        }

        return [$alc, $pc];
    }

    /**
     * @return array<int, array<string, int>>
     */
    private function grouped(Collection $rows, int $shiftDays = 0): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->cra][CarbonImmutable::parse($row->day)->addDays($shiftDays)->toDateString()] = (int) $row->n;
        }

        return $out;
    }
}
