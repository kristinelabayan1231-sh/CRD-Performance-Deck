<?php

namespace App\Services;

use App\Models\CustomerAccount;
use App\Models\CustomerLink;
use App\Models\Lead;
use Illuminate\Support\Facades\Cache;

/**
 * CRA Dashboard → Check who gets these (supervisors): customers with an open lead whose CRA assignments disagree, so the
 * lead rules may be handing their leads to someone a supervisor didn't expect. A conflict is a High AOV / VIP CRA who
 * isn't the CRA with the most orders, or a claim by someone other than the High AOV / VIP CRA. (A claim beating the
 * most-orders CRA is what a claim is for, so that alone isn't one.) The rules pick claim, then High AOV / VIP CRA, then
 * most orders. Worked out ahead by leads:refresh-conflicts.
 */
class LeadConflicts
{
    private const CACHE_KEY = 'leads.conflicts';

    /** How long a worked-out list is kept: past the next leads:refresh-conflicts run (every 10 minutes). */
    public const CACHE_MINUTES = 25;

    public function __construct(private CustomerOwners $owners, private HighValueCustomers $highValue) {}

    /**
     * The last worked-out list (leads:refresh-conflicts, every 10 minutes), empty until the first run.
     *
     * @return list<array{phone_key: string, customer_name: string, phone_number: string, lead_id: int, listed_on: string, product_name: ?string, holder_id: ?int, claim_id: ?int, high_value_id: ?int, high_value_label: ?string, orders_id: ?int, orders_count: int, rules_id: ?int}>
     */
    public function all(): array
    {
        // Never worked out inside a page view: until leads:refresh-conflicts has run there's nothing to show.
        return Cache::get(self::CACHE_KEY) ?? [];
    }

    /**
     * Take a lead a supervisor just assigned off the kept list now, without waiting for the next refresh.
     */
    public function forget(int $leadId): void
    {
        $rows = Cache::get(self::CACHE_KEY);
        if ($rows === null) {
            return;
        }

        Cache::put(self::CACHE_KEY, array_values(array_filter($rows, fn (array $row) => $row['lead_id'] !== $leadId)), now()->addMinutes(self::CACHE_MINUTES));
    }

    /**
     * Work the list out again and keep it for CACHE_MINUTES.
     *
     * @return list<array<string, mixed>>
     */
    public function refresh(): array
    {
        $rows = $this->build();
        Cache::put(self::CACHE_KEY, $rows, now()->addMinutes(self::CACHE_MINUTES));

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function build(): array
    {
        // Every conflict has a High AOV / VIP CRA, so only those customers are checked.
        $highValueOf = CustomerAccount::whereNotNull('assigned_cra_id')->pluck('assigned_cra_id', 'phone_key')
            ->mapWithKeys(fn ($craId, $key) => [(string) $key => (int) $craId])->all();
        if ($highValueOf === []) {
            return [];
        }

        $primaryOf = [];
        foreach (array_keys($highValueOf) as $key) {
            $primaryOf[(string) $key] = (string) $key;
        }
        foreach (array_chunk(array_keys($highValueOf), 1000) as $chunk) {
            CustomerLink::whereIn('primary_phone_key', $chunk)->get(['phone_key', 'primary_phone_key'])
                ->each(function (CustomerLink $link) use (&$primaryOf) {
                    $primaryOf[(string) $link->phone_key] = (string) $link->primary_phone_key;
                });
        }

        // Each of their latest open lead, by main number.
        $leads = [];
        foreach (array_chunk(array_keys($primaryOf), 500) as $chunk) {
            $variants = collect($chunk)->flatMap(fn ($key) => [$key, '0'.$key, '63'.$key, '+63'.$key])->all();
            foreach (Lead::unprocessed()->notConverted()->withoutPosSales()->whereIn('phone_number', $variants)
                ->orderBy('listed_on')->orderBy('id')
                ->get(['id', 'customer_name', 'phone_number', 'product_name', 'assigned_to', 'listed_on', 'assignment_checked_at']) as $lead) {
                $customer = $primaryOf[LeadGenerator::normalizePhone((string) $lead->phone_number)] ?? null;
                if ($customer !== null && (! isset($leads[$customer]) || ! $lead->listed_on->lessThan($leads[$customer]->listed_on))) {
                    $leads[$customer] = $lead;
                }
            }
        }

        if ($leads === []) {
            return [];
        }

        $keys = array_map('strval', array_keys($leads));
        $rows = [];

        foreach (array_chunk($keys, 2000) as $chunk) {
            $claims = $this->owners->claimed($chunk);
            $byOrders = $this->owners->byOrders($chunk);

            foreach ($chunk as $key) {
                $claim = $claims[$key] ?? null;
                $high = $highValueOf[$key] ?? null;
                $most = $byOrders[$key] ?? null;
                $isConflict = ($high !== null && $most !== null && $high !== $most) || ($claim !== null && $high !== null && $claim !== $high);

                $lead = $leads[$key];

                // A supervisor already chose this lead's CRA from the list.
                if (! $isConflict || $lead->assignment_checked_at !== null) {
                    continue;
                }

                $rows[$key] = [
                    'phone_key' => $key,
                    'customer_name' => $lead->customer_name ?: 'Unknown',
                    'phone_number' => (string) $lead->phone_number,
                    'lead_id' => $lead->id,
                    'listed_on' => $lead->listed_on->toDateString(),
                    'product_name' => $lead->product_name,
                    'holder_id' => $lead->assigned_to,
                    'claim_id' => $claim,
                    'high_value_id' => $high,
                    'orders_id' => $most,
                    'rules_id' => $claim ?? $high ?? $most,
                ];
            }
        }

        if ($rows === []) {
            return [];
        }

        $flags = $this->highValue->all();
        $counts = $this->owners->orderCounts(array_map('strval', array_keys($rows)));

        return collect($rows)
            ->map(fn (array $row) => [
                ...$row,
                'high_value_label' => ($flags[$row['phone_key']]['vip'] ?? false) ? 'VIP' : 'High AOV',
                'orders_count' => (int) ($counts[$row['phone_key']][$row['orders_id']] ?? 0),
            ])
            ->sortBy([['listed_on', 'desc'], ['customer_name', 'asc']])
            ->values()->all();
    }
}
