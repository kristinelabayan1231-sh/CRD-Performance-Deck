<?php

namespace App\Services;

use App\Models\CvrOrderEntry;
use App\Models\LogisticsOrder;
use App\Models\PancakeOrder;
use App\Support\PancakeAccounts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The dashboard's Retained and Repeat tiles for months before the Segmentation Tracker recorded purchases
 * (config segmentation.tracker_repeats_from). Shecom's retention report counted per customer (one per number):
 * Retained = FSD-delivered customers with a later CRA order; Repeat = CRD-delivered customers with a later CRA order.
 */
class DeliveredRetention
{
    public const SOURCE = 'deliveries';

    private const CACHE_MINUTES = 10;

    /**
     * Whether dates ending on $to are all before the tracker recorded purchases.
     */
    public static function covers(CarbonImmutable $to): bool
    {
        return $to->toDateString() < config('segmentation.tracker_repeats_from');
    }

    /**
     * @return array{source: string, leads: array{retained: int, repeat: int}, retained: int, repeat: int}
     */
    public function customers(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return Cache::remember(
            "delivered-retention.{$from->toDateString()}.{$to->toDateString()}",
            now()->addMinutes(self::CACHE_MINUTES),
            fn () => $this->count($from, $to),
        );
    }

    /**
     * customers() per day, so the days of a range add up to it: a customer counts on their first delivery in the
     * range, and as bought again on their first delivery in the range followed by a CRA order.
     *
     * @return array<string, array{retained_base: int, repeat_base: int, retained: int, repeat: int}>
     */
    public function perDay(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $deliveries = LogisticsOrder::covered()->where('non_crd', false)
            ->whereDate('delivered_date', '>=', $from)->whereDate('delivered_date', '<=', $to)
            ->orderBy('delivered_date')
            ->get(['team', 'phone_key', 'delivered_date']);
        $lastCraOrder = $this->lastCraOrders($from);
        $days = [];
        $seen = [];

        foreach ($deliveries as $delivery) {
            [$base, $bought] = $delivery->team === LogisticsOrder::TEAM_CRD ? ['repeat_base', 'repeat'] : ['retained_base', 'retained'];
            $day = $delivery->delivered_date->toDateString();
            $days[$day] ??= ['retained_base' => 0, 'repeat_base' => 0, 'retained' => 0, 'repeat' => 0];

            if (! isset($seen[$base][$delivery->phone_key])) {
                $seen[$base][$delivery->phone_key] = true;
                $days[$day][$base]++;
            }
            if (! isset($seen[$bought][$delivery->phone_key]) && ($lastCraOrder[$delivery->phone_key] ?? '') > $day) {
                $seen[$bought][$delivery->phone_key] = true;
                $days[$day][$bought]++;
            }
        }

        return $days;
    }

    /**
     * The Conversion rate tile without the figures built on tracker leads, which those months don't have.
     *
     * @param  array<string, array{customers: int, base: int, rate: ?float, daily: array<string, ?float>}>  $conversion
     * @return array<string, mixed>
     */
    public static function withoutTrackerRates(array $conversion): array
    {
        foreach (['all', 'sc'] as $key) {
            $conversion[$key]['rate'] = null;
            $conversion[$key]['daily'] = array_map(fn () => null, $conversion[$key]['daily']);
        }

        return [...$conversion, 'no_tracker' => true];
    }

    /**
     * @return array{source: string, leads: array{retained: int, repeat: int}, retained: int, repeat: int}
     */
    private function count(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $deliveries = LogisticsOrder::covered()->where('non_crd', false)
            ->whereDate('delivered_date', '>=', $from)->whereDate('delivered_date', '<=', $to)
            ->get(['team', 'phone_key', 'delivered_date']);
        $lastCraOrder = $this->lastCraOrders($from);

        $boughtAgain = fn (Collection $rows) => $rows
            ->filter(fn (LogisticsOrder $delivery) => ($lastCraOrder[$delivery->phone_key] ?? '') > $delivery->delivered_date->toDateString())
            ->pluck('phone_key')->unique()->count();
        $fsd = $deliveries->where('team', LogisticsOrder::TEAM_FSD);
        $crd = $deliveries->where('team', LogisticsOrder::TEAM_CRD);

        return [
            'source' => self::SOURCE,
            'leads' => [
                'retained' => $fsd->pluck('phone_key')->unique()->count(),
                'repeat' => $crd->pluck('phone_key')->unique()->count(),
            ],
            'retained' => $boughtAgain($fsd),
            'repeat' => $boughtAgain($crd),
        ];
    }

    /**
     * Each customer's latest order (Y-m-d) on or after $from made by the CRD team or credited to a CRA, not canceled.
     *
     * @return Collection<string, string>
     */
    private function lastCraOrders(CarbonImmutable $from): Collection
    {
        $sellers = array_values(array_unique([
            ...PancakeOrder::crdAccounts(),
            ...PancakeAccounts::craBySeller(LeadGenerator::cras())->keys(),
        ]));
        $claimed = CvrOrderEntry::whereNotNull('user_id')->pluck('pancake_order_id');

        return PancakeOrder::crdProducts()->counted()->whereNotNull('phone_key')
            ->whereDate('ordered_on', '>=', $from)
            ->where(fn ($query) => $query->whereIn('seller_name', $sellers)->orWhereIn('pancake_order_id', $claimed))
            ->selectRaw('phone_key, max(ordered_on) as last_ordered_on')
            ->groupBy('phone_key')
            ->pluck('last_ordered_on', 'phone_key')
            ->map(fn ($day) => substr((string) $day, 0, 10));
    }
}
