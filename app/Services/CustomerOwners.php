<?php

namespace App\Services;

use App\Models\CustomerClaim;
use App\Models\CustomerHistory;
use App\Models\CustomerLink;
use App\Models\CvrOrderEntry;
use App\Models\PancakeEngagement;
use App\Models\PancakeOrder;
use App\Models\User;
use App\Support\PancakeAccounts;
use Carbon\CarbonImmutable;

/**
 * Each customer's own CRA: the CRA who claimed them from the up-for-reassignment list (CustomerReassignment), else the
 * one who has handled the most of their orders and, when CRAs are level, the one who handled the customer last. So one
 * order is enough, two beat a CRA with one, and five beat a later CRA with two. The
 * customer's leads go to them from then on (LeadGenerator, BacklogFill, ExclusiveLeads). An order is handled by the CRA
 * it was claimed for in the CVR Tracker, else the CRA whose Pancake account sold it; canceled and deleted orders don't
 * count, and merged numbers (Customer Database) are one customer. Orders from before the saved Pancake orders count
 * too (CustomerHistory). Only active CRAs own customers.
 */
class CustomerOwners
{
    /** Orders a CRA must have handled to get a new High AOV / VIP customer. */
    public const HIGH_VALUE_ORDERS = 2;

    /**
     * The own CRA of each of these numbers' customers, by number (numbers with none left out).
     *
     * @param  list<string>  $phoneKeys
     * @return array<string, int> phone key => CRA id
     */
    public function for(array $phoneKeys): array
    {
        return $this->claimed(array_values(array_unique(array_filter($phoneKeys)))) + $this->byOrders($phoneKeys);
    }

    /**
     * The CRA who has handled the most of each of these numbers' customers' orders (the last of them when level), claims
     * left out: what for() gives when nobody has claimed the customer.
     *
     * @param  list<string>  $phoneKeys
     * @return array<string, int> phone key => CRA id
     */
    public function byOrders(array $phoneKeys): array
    {
        return $this->pick($phoneKeys, true, fn (array $byCra) => collect($byCra)
            ->map(fn (array $counted, int $craId) => [...$counted, 'cra' => $craId])
            ->sortBy([['count', 'desc'], ['latest', 'desc'], ['cra', 'asc']])
            ->first()['cra']);
    }

    /**
     * The active CRA who claimed each of these numbers' customers (numbers with no claim left out).
     *
     * @param  list<string>  $phoneKeys
     * @return array<string, int> phone key => CRA id
     */
    public function claimed(array $phoneKeys): array
    {
        if ($phoneKeys === [] || ! CustomerClaim::query()->exists()) {
            return [];
        }

        $primaryOf = $this->primaries($phoneKeys);
        $active = LeadGenerator::cras()->pluck('id')->flip();
        // A loop, not flatMap: numeric phone keys would be renumbered.
        $claims = [];
        foreach (array_chunk(array_values(array_unique($primaryOf)), 1000) as $chunk) {
            $claims += CustomerClaim::whereIn('phone_key', $chunk)->pluck('user_id', 'phone_key')->all();
        }

        return collect($primaryOf)
            ->map(fn (string $primary) => $claims[$primary] ?? null)
            ->filter(fn (?int $craId) => $craId !== null && isset($active[$craId]))
            ->all();
    }

    /**
     * How many of each customer's orders each active CRA has handled, counted as for() does.
     *
     * @param  list<string>  $phoneKeys
     * @return array<string, array<int, int>> phone key => CRA id => orders
     */
    public function orderCounts(array $phoneKeys): array
    {
        return $this->pick($phoneKeys, true, fn (array $byCra) => array_map(fn (array $counted) => $counted['count'], $byCra));
    }

    /**
     * The CRA a new High AOV / VIP customer goes to (HighValueCustomers): the one who has handled HIGH_VALUE_ORDERS of
     * their orders, the latest such CRA when there are several; numbers with none left out.
     *
     * @param  list<string>  $phoneKeys
     * @return array<string, int> phone key => CRA id
     */
    public function forHighValue(array $phoneKeys): array
    {
        return $this->pick($phoneKeys, false, fn (array $byCra) => collect($byCra)
            ->filter(fn (array $counted) => $counted['count'] >= self::HIGH_VALUE_ORDERS)
            ->sortByDesc('latest')
            ->keys()->first());
    }

    /**
     * @param  list<string>  $phoneKeys
     * @param  bool  $withHistory  also count orders older than the saved ones (CustomerHistory)
     * @param  callable(array<int, array{count: int, first: string, latest: string}>): ?int  $choose  per customer, from their CRAs' tallies
     * @return array<string, int> phone key => CRA id
     */
    private function pick(array $phoneKeys, bool $withHistory, callable $choose): array
    {
        $phoneKeys = array_values(array_unique(array_filter($phoneKeys)));
        $cras = LeadGenerator::cras();
        $bySeller = PancakeAccounts::craBySeller($cras)->map(fn (User $cra) => $cra->id);

        if ($phoneKeys === [] || $cras->isEmpty()) {
            return [];
        }

        // Every number of each customer: a merged number counts with its main one.
        $primaryOf = $this->primaries($phoneKeys);
        $numbersOf = collect($primaryOf)->unique()->values()
            ->mapWithKeys(fn (string $primary) => [$primary => [$primary]])->all();
        foreach (array_chunk(array_keys($numbersOf), 1000) as $chunk) {
            CustomerLink::whereIn('primary_phone_key', $chunk)->get(['phone_key', 'primary_phone_key'])
                ->each(function (CustomerLink $link) use (&$numbersOf, &$primaryOf) {
                    $numbersOf[$link->primary_phone_key][] = $link->phone_key;
                    $primaryOf[$link->phone_key] = $link->primary_phone_key;
                });
        }

        $numbers = array_values(array_unique(array_merge(...array_values($numbersOf))));
        $active = $cras->pluck('id')->flip();

        // Per customer and CRA: how many orders they handled, and when the first and latest were.
        $handled = [];
        $tally = function (string $customer, int $craId, string $at) use (&$handled) {
            $handled[$customer][$craId]['count'] = ($handled[$customer][$craId]['count'] ?? 0) + 1;
            $handled[$customer][$craId]['first'] = min($handled[$customer][$craId]['first'] ?? $at, $at);
            $handled[$customer][$craId]['latest'] = max($handled[$customer][$craId]['latest'] ?? $at, $at);
        };

        $oldestDay = [];
        // Plain rows, a chunk at a time: a day's backlog reaches tens of thousands of orders, too many to hold as models.
        foreach (array_chunk($numbers, 1000) as $chunk) {
            $orders = PancakeOrder::whereIn('phone_key', $chunk)->counted()->toBase()
                ->get(['pancake_order_id', 'phone_key', 'seller_name', 'ordered_on', 'ordered_at']);
            $claimed = CvrOrderEntry::whereNotNull('user_id')->whereIn('pancake_order_id', $orders->pluck('pancake_order_id'))
                ->pluck('user_id', 'pancake_order_id');

            foreach ($orders as $order) {
                $customer = $primaryOf[$order->phone_key] ?? $order->phone_key;
                $at = $order->ordered_at !== null ? substr((string) $order->ordered_at, 0, 19) : substr((string) $order->ordered_on, 0, 10).' 00:00:00';
                $oldestDay[$customer] = min($oldestDay[$customer] ?? '9999', substr($at, 0, 10));

                $craId = isset($claimed[$order->pancake_order_id]) ? (int) $claimed[$order->pancake_order_id] : ($bySeller[$order->seller_name] ?? null);
                if ($craId !== null && isset($active[$craId])) {
                    $tally($customer, $craId, $at);
                }
            }
        }

        // The history repeats most saved orders: only what's older than the customer's oldest saved order is new here.
        foreach ($withHistory ? array_chunk($numbers, 1000) : [] as $chunk) {
            CustomerHistory::whereIn('phone_key', $chunk)->whereNotNull('prior_cra_sales')->get(['phone_key', 'prior_cra_sales'])
                ->each(function (CustomerHistory $history) use ($primaryOf, $bySeller, $active, $oldestDay, $tally) {
                    $customer = $primaryOf[$history->phone_key] ?? $history->phone_key;

                    foreach ($history->prior_cra_sales as $sale) {
                        $at = CarbonImmutable::parse($sale['at']);
                        $craId = $bySeller[$sale['seller']] ?? null;

                        if ($craId !== null && isset($active[$craId]) && $at->toDateString() < ($oldestDay[$customer] ?? '9999')) {
                            $tally($customer, $craId, $at->toDateTimeString());
                        }
                    }
                });
        }

        $ownerOf = collect($handled)->map($choose)->filter();

        return collect($phoneKeys)
            ->mapWithKeys(fn (string $phone) => [$phone => $ownerOf[$primaryOf[$phone] ?? $phone] ?? null])
            ->filter()
            ->all();
    }

    /**
     * The active CRA who handled the customer's latest CRA-handled order (all their merged numbers; claimed in the
     * CVR Tracker, else the CRA whose Pancake account sold it), with the order day. Null when none did.
     *
     * @return array{cra: User, ordered_on: CarbonImmutable}|null
     */
    public function latestHandler(string $phoneKey): ?array
    {
        $cras = LeadGenerator::cras()->keyBy('id');
        $bySeller = PancakeAccounts::craBySeller($cras);
        $primary = $this->primaries([$phoneKey])[$phoneKey];
        $numbers = [$primary, ...CustomerLink::where('primary_phone_key', $primary)->pluck('phone_key')->all()];

        $orders = PancakeOrder::whereIn('phone_key', $numbers)->counted()
            ->orderByDesc('ordered_on')->orderByDesc('ordered_at')->orderByDesc('id')
            ->get(['pancake_order_id', 'seller_name', 'ordered_on']);
        $claimed = CvrOrderEntry::whereNotNull('user_id')->whereIn('pancake_order_id', $orders->pluck('pancake_order_id'))->pluck('user_id', 'pancake_order_id');

        foreach ($orders as $order) {
            $cra = isset($claimed[$order->pancake_order_id])
                ? $cras->get($claimed[$order->pancake_order_id])
                : $bySeller->get(PancakeEngagement::staffKey((string) $order->seller_name));

            if ($cra !== null) {
                return ['cra' => $cra, 'ordered_on' => $order->ordered_on];
            }
        }

        return null;
    }

    /**
     * Each number's main number (itself when it isn't merged into another).
     *
     * @param  list<string>  $phoneKeys
     * @return array<string, string>
     */
    private function primaries(array $phoneKeys): array
    {
        $primaryOf = array_combine($phoneKeys, $phoneKeys);

        foreach (array_chunk($phoneKeys, 1000) as $chunk) {
            CustomerLink::whereIn('phone_key', $chunk)->pluck('primary_phone_key', 'phone_key')
                // Numeric phone keys come back as ints.
                ->each(function (string $primary, int|string $phone) use (&$primaryOf) {
                    $primaryOf[$phone] = $primary;
                });
        }

        return $primaryOf;
    }
}
