<?php

namespace App\Services;

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
     * @return list<array{phone_key: string, customer_name: string, phone_number: string, lead_id: int, listed_on: string, product_name: ?string, holder_id: ?int, claim_id: ?int, high_value_id: ?int, high_value_label: ?string, orders_id: ?int, orders_count: int, rules_id: ?int}>
     */
    public function all(): array
    {
        return Cache::get(self::CACHE_KEY) ?? $this->refresh();
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
        // Each customer's latest open lead.
        $leads = [];
        foreach (Lead::unprocessed()->notConverted()->withoutPosSales()->orderBy('listed_on')->orderBy('id')
            ->get(['id', 'customer_name', 'phone_number', 'product_name', 'assigned_to', 'listed_on']) as $lead) {
            $key = LeadGenerator::normalizePhone((string) $lead->phone_number);
            if ($key !== '') {
                $leads[$key] = $lead;
            }
        }

        if ($leads === []) {
            return [];
        }

        $keys = array_map('strval', array_keys($leads));
        $primaryOf = CustomerLink::whereIn('phone_key', $keys)->pluck('primary_phone_key', 'phone_key')->map(fn ($key) => (string) $key)->all();
        $rows = [];

        foreach (array_chunk($keys, 2000) as $chunk) {
            $claims = $this->owners->claimed($chunk);
            $highValue = $this->highValue->crasFor($chunk);
            $byOrders = $this->owners->byOrders($chunk);

            foreach ($chunk as $key) {
                $claim = $claims[$key] ?? null;
                $high = $highValue[$key] ?? null;
                $most = $byOrders[$key] ?? null;
                $isConflict = ($high !== null && $most !== null && $high !== $most) || ($claim !== null && $high !== null && $claim !== $high);

                if (! $isConflict) {
                    continue;
                }

                $lead = $leads[$key];
                $customer = $primaryOf[$key] ?? $key;
                $rows[$customer] = [
                    'phone_key' => $customer,
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
