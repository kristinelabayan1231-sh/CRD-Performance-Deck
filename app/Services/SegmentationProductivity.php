<?php

namespace App\Services;

use App\Models\Lead;
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
 * Assigned lead conversion = customers with a Pancake order that day who are in the CRA's leads with Repeat Purchase = Yes.
 * Pancake conversion = customers on the CRA's own Pancake orders that day who are not in any CRA's assigned leads.
 * Total confirmed = both conversions. Conversion rate = confirmed ÷ answered. Pick-up rate = answered ÷ assigned.
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
            Lead::whereIn('assigned_to', $ids)
                ->whereDate('est_out_of_stock_date', '>=', $from->subDays($lag))->whereDate('est_out_of_stock_date', '<=', $to->subDays($lag))
                ->selectRaw('assigned_to as cra, date(est_out_of_stock_date) as day, count(*) as n')->groupBy('cra', 'day')->get(),
            $lag,
        );

        $calls = $this->grouped(
            Lead::whereIn('assigned_to', $ids)->whereDate('contact_date', '>=', $from)->whereDate('contact_date', '<=', $to)
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
     * Customers converted per CRA per day: [assigned lead conversion, Pancake conversion].
     *
     * @param  Collection<int, int>  $ids
     * @param  Collection<string, int>  $accounts  Pancake account name => CRA id
     * @return array{0: array<int, array<string, int>>, 1: array<int, array<string, int>>}
     */
    private function conversions(Collection $ids, Collection $accounts, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $orders = PancakeOrder::counted()
            ->whereDate('ordered_on', '>=', $from)->whereDate('ordered_on', '<=', $to)
            ->get(['pancake_order_id', 'ordered_on', 'seller_name', 'phone_key']);

        if ($orders->isEmpty()) {
            return [[], []];
        }

        // Customers the CRA marked Repeat Purchase = Yes; the most recent assignment wins a shared number.
        $repeatBuyers = Lead::whereIn('assigned_to', $ids)->where('repeat_purchase', 'yes')
            ->orderBy('assigned_at')->orderBy('id')
            ->get(['phone_number', 'assigned_to'])
            ->mapWithKeys(fn (Lead $lead) => [LeadGenerator::normalizePhone($lead->phone_number) => $lead->assigned_to])
            ->forget('');

        // Every customer on anyone's assigned leads list.
        $assignedPhones = Lead::whereNotNull('assigned_to')->pluck('phone_number')
            ->map(fn (string $phone) => LeadGenerator::normalizePhone($phone))
            ->filter()->flip();

        $alc = [];
        $pc = [];

        foreach ($orders->groupBy(fn (PancakeOrder $order) => $order->ordered_on->toDateString()) as $day => $dayOrders) {
            // Assigned lead conversion: any seller, counted once per customer.
            $dayOrders->filter(fn (PancakeOrder $order) => $order->phone_key && isset($repeatBuyers[$order->phone_key]))
                ->groupBy('phone_key')
                ->each(function (Collection $theirs, string $phone) use ($repeatBuyers, &$alc, $day) {
                    $cra = $repeatBuyers[$phone];
                    $alc[$cra][$day] = ($alc[$cra][$day] ?? 0) + 1;
                });

            // Pancake conversion: the CRA's own orders for customers outside every leads list.
            $dayOrders->filter(fn (PancakeOrder $order) => isset($accounts[$order->seller_name])
                    && ! ($order->phone_key && isset($assignedPhones[$order->phone_key])))
                ->groupBy(fn (PancakeOrder $order) => $accounts[$order->seller_name])
                ->each(function (Collection $mine, int $cra) use (&$pc, $day) {
                    $pc[$cra][$day] = $mine->map(fn (PancakeOrder $order) => $order->phone_key ?: 'order:'.$order->pancake_order_id)->unique()->count();
                });
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
