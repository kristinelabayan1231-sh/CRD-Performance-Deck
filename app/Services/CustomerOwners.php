<?php

namespace App\Services;

use App\Models\CustomerLink;
use App\Models\CvrOrderEntry;
use App\Models\PancakeEngagement;
use App\Models\PancakeOrder;
use App\Models\User;
use App\Support\PancakeAccounts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Each customer's own CRA for the Segmentation Tracker: a CRA owns a customer once they have handled
 * ORDERS_TO_OWN of their orders, so the customer's leads go to them from then on (LeadGenerator, BacklogFill).
 * An order is handled by the CRA it was claimed for in the CVR Tracker, else the CRA whose Pancake account sold it;
 * canceled and deleted orders don't count, and merged numbers (Customer Database) are one customer. With more than
 * one such CRA, the one who handled the latest order. Only active CRAs own customers.
 */
class CustomerOwners
{
    public const ORDERS_TO_OWN = 2;

    /**
     * The own CRA of each of these numbers' customers, by number (numbers with none left out).
     *
     * @param  list<string>  $phoneKeys
     * @return array<string, int> phone key => CRA id
     */
    public function for(array $phoneKeys): array
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

        $orders = $this->orders(array_values(array_unique(array_merge(...array_values($numbersOf)))));
        // Keyed by order number (numeric strings), so the chunks are joined with + to keep the keys.
        $claimed = $orders->pluck('pancake_order_id')->chunk(1000)->reduce(fn (array $all, Collection $ids) => $all
            + CvrOrderEntry::whereNotNull('user_id')->whereIn('pancake_order_id', $ids->values())->pluck('user_id', 'pancake_order_id')->all(), []);
        $active = $cras->pluck('id')->flip();

        // Per customer and CRA: how many orders they handled and when the latest was.
        $handled = [];
        foreach ($orders as $order) {
            $craId = isset($claimed[$order->pancake_order_id]) ? (int) $claimed[$order->pancake_order_id] : ($bySeller[$order->seller_name] ?? null);
            if ($craId === null || ! isset($active[$craId])) {
                continue;
            }

            $customer = $primaryOf[$order->phone_key] ?? $order->phone_key;
            $handled[$customer][$craId]['count'] = ($handled[$customer][$craId]['count'] ?? 0) + 1;
            $handled[$customer][$craId]['latest'] = max($handled[$customer][$craId]['latest'] ?? '', (string) $order->ordered_at);
        }

        $ownerOf = collect($handled)->map(fn (array $byCra) => collect($byCra)
            ->filter(fn (array $tally) => $tally['count'] >= self::ORDERS_TO_OWN)
            ->sortByDesc('latest')
            ->keys()->first())
            ->filter();

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

    /**
     * The counted Pancake orders of these numbers.
     *
     * @param  list<string>  $numbers
     * @return Collection<int, PancakeOrder>
     */
    private function orders(array $numbers): Collection
    {
        return collect(array_chunk($numbers, 1000))->flatMap(fn (array $chunk) => PancakeOrder::whereIn('phone_key', $chunk)->counted()
            ->get(['pancake_order_id', 'phone_key', 'seller_name', 'ordered_at']));
    }
}
