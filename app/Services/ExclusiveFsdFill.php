<?php

namespace App\Services;

use App\Models\DeliveredOrder;
use App\Models\ExclusiveReservedLead;
use App\Models\Lead;
use App\Models\LogisticsOrder;
use App\Models\PancakeOrder;
use App\Models\Product;
use App\Models\User;
use App\Support\NicheRules;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tops up an exclusive CRA's month when the leads in the tracker can't reach the target: FSD deliveries from the Customer
 * Database that never became leads become new unassigned FSD leads, held for that CRA so no other CRA gets them. Only
 * customers no CRA has handled, past their out-of-stock date, with no order since, newest deliveries first.
 */
class ExclusiveFsdFill
{
    private const CHUNK = 1000;

    /**
     * Up to $limit new leads for the CRA, newest deliveries first, in the shape they are saved.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function candidates(User $cra, CarbonImmutable $day, int $limit): Collection
    {
        $found = collect();

        if ($limit <= 0) {
            return $found;
        }

        $catalog = new ProductCatalog;
        $niches = NicheRules::current();
        $highValue = app(HighValueCustomers::class);
        $generator = app(LeadGenerator::class);

        foreach ($this->latestFsdDeliveries()->chunk(self::CHUNK) as $deliveries) {
            $orderIds = $deliveries->pluck('order_id')->all();
            $savedQty = DeliveredOrder::whereIn('order_id', $orderIds)->pluck('qty', 'order_id');
            $orderItems = PancakeOrder::whereIn('pancake_order_id', $orderIds)->get(['pancake_order_id', 'items'])->pluck('items', 'pancake_order_id');
            $owners = $generator->ownersFor($deliveries->pluck('phone_key')->values()->all(), $highValue);

            foreach ($deliveries as $delivery) {
                $delivered = substr((string) $delivery->delivered_date, 0, 10);
                $product = $catalog->match((string) $delivery->product, $delivered);
                $days = (int) $product?->consumption_days;

                if ($days <= 0
                    || isset($owners['high_value'][$delivery->phone_key]) || isset($owners['handled'][$delivery->phone_key])
                    || ! $niches->allows($cra->id, $product->name)) {
                    continue;
                }

                $qty = (int) ($delivery->qty ?? $savedQty[$delivery->order_id] ?? $this->qtyInItems($orderItems[$delivery->order_id] ?? [], $product, $catalog, $delivered));

                // Without a quantity the out-of-stock date would be a guess.
                if ($qty <= 0) {
                    continue;
                }

                $outOfStock = CarbonImmutable::parse($delivered)->addDays($qty * $days - 1);

                if ($outOfStock->greaterThan($day)) {
                    continue;
                }

                $found->push([
                    'order_id' => (string) $delivery->order_id,
                    'customer_name' => trim((string) $delivery->customer_name),
                    'phone_number' => (string) $delivery->phone_number,
                    'product_name' => $product->name,
                    'product_raw' => (string) $delivery->product,
                    'qty' => $qty,
                    'qty_unknown' => false,
                    'delivered_date' => $delivered,
                    'consumption_days' => $days,
                    'est_out_of_stock_date' => $outOfStock->toDateString(),
                    'listed_on' => $outOfStock->toDateString(),
                    'lead_type' => Lead::TYPE_FSD,
                ]);

                if ($found->count() >= $limit) {
                    return $found;
                }
            }
        }

        return $found;
    }

    /**
     * Save the new leads, unassigned and held for the CRA from the start. Order numbers already in the tracker are skipped.
     *
     * @param  Collection<int, array<string, mixed>>  $leads  from candidates()
     * @return int leads added
     */
    public function add(User $cra, CarbonImmutable $day, Collection $leads): int
    {
        $month = $day->addDays(WorkingDate::lagDays())->startOfMonth()->toDateString();
        $now = now();
        $added = 0;

        foreach ($leads->chunk(500) as $chunk) {
            DB::transaction(function () use ($chunk, $cra, $month, $now, &$added) {
                $orderIds = $chunk->pluck('order_id')->values()->all();
                $added += Lead::insertOrIgnore($chunk->map(fn (array $lead) => [...$lead, 'created_at' => $now, 'updated_at' => $now])->values()->all());

                ExclusiveReservedLead::insertOrIgnore(
                    Lead::whereIn('order_id', $orderIds)->whereNull('assigned_to')->pluck('id')
                        ->map(fn (int $id) => [
                            'user_id' => $cra->id, 'month' => $month, 'lead_id' => $id, 'source' => ExclusiveLeads::RANDOM,
                            'created_at' => $now, 'updated_at' => $now,
                        ])->all()
                );
            });
        }

        return $added;
    }

    /**
     * How many of the product the order's items hold.
     *
     * @param  array<int, array{name?: string, qty?: int}>|null  $items
     */
    private function qtyInItems(?array $items, Product $product, ProductCatalog $catalog, string $on): int
    {
        return collect($items ?? [])
            ->filter(fn (array $item) => $catalog->match((string) ($item['name'] ?? ''), $on)?->is($product))
            ->sum(fn (array $item) => (int) ($item['qty'] ?? 0));
    }

    /**
     * Each customer's latest delivery, newest first, when FSD delivered it and they have no lead, no order from a CRD
     * account and no order since.
     *
     * @return Collection<int, object>
     */
    private function latestFsdDeliveries(): Collection
    {
        $hasLead = [];
        foreach (Lead::query()->toBase()->select('phone_number')->cursor() as $lead) {
            $hasLead[LeadGenerator::normalizePhone((string) $lead->phone_number)] = true;
        }

        $crdCustomers = PancakeOrder::whereIn('seller_name', PancakeOrder::crdAccounts())->whereNotNull('phone_key')
            ->distinct()->pluck('phone_key')->flip();
        $lastOrder = PancakeOrder::counted()->whereNotNull('phone_key')->toBase()
            ->selectRaw('phone_key, max(ordered_on) as last_on')->groupBy('phone_key')->pluck('last_on', 'phone_key');

        $latest = [];
        foreach (LogisticsOrder::covered()->where('non_crd', false)->whereNotNull('phone_key')->orderBy('delivered_date')->orderBy('id')->toBase()
            ->select(['order_id', 'team', 'customer_name', 'phone_number', 'phone_key', 'product', 'qty', 'delivered_date'])->cursor() as $delivery) {
            $latest[$delivery->phone_key] = $delivery;
        }

        return collect($latest)
            ->filter(fn (object $delivery, string $phone) => $delivery->team === LogisticsOrder::TEAM_FSD && strlen($phone) === 10
                && ! isset($hasLead[$phone]) && ! isset($crdCustomers[$phone])
                && substr((string) ($lastOrder[$phone] ?? ''), 0, 10) <= substr((string) $delivery->delivered_date, 0, 10))
            ->sortByDesc(fn (object $delivery) => substr((string) $delivery->delivered_date, 0, 10))
            ->values();
    }
}
