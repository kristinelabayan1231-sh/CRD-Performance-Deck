<?php

namespace App\Services;

use App\Models\DeliveredOrder;
use App\Models\PancakeEngagement;
use App\Models\PancakeOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

use function Illuminate\Support\defer;

class PancakeSync
{
    public function __construct(private PancakeClient $client) {}

    /**
     * Copy one day's chat engagements and POS orders from Pancake. Rows are
     * updated in place by their Pancake id; nothing is deleted.
     *
     * @return array{staff: int, orders: int, delivered: int}
     */
    public function sync(CarbonImmutable $day): array
    {
        // The lead fallback's orders; a failure here mustn't stop the engagements and orders below.
        $delivered = rescue(fn () => $this->syncDelivered($day), 0);

        $day = $day->startOfDay();
        Cache::put(self::runningKey($day), true, now()->addMinutes(15));

        try {
            $engagements = $this->client->engagements($day);
            $orders = collect($this->client->orders($day))->map(fn (array $order) => $this->toOrder($order))->filter()->values();
        } finally {
            Cache::forget(self::runningKey($day));
        }

        $now = now();

        DB::transaction(function () use ($day, $engagements, $orders, $now) {
            PancakeEngagement::upsert(
                collect($engagements)->map(fn (array $row, string $id) => [
                    'date' => $day->toDateString(),
                    'pancake_user_id' => $id,
                    'staff_name' => PancakeEngagement::staffKey($row['name']),
                    'engagements' => $row['engagements'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->values()->all(),
                ['date', 'pancake_user_id'],
                ['staff_name', 'engagements', 'updated_at'],
            );

            foreach ($orders->chunk(200) as $chunk) {
                PancakeOrder::upsert(
                    $chunk->map(fn (array $row) => [...$row, 'created_at' => $now, 'updated_at' => $now])->all(),
                    ['pancake_order_id'],
                    ['ordered_on', 'ordered_at', 'seller_pancake_id', 'seller_name', 'customer_name', 'phone_number',
                        'phone_key', 'status', 'status_name', 'total_price', 'page_name', 'tags', 'conversion_type', 'updated_at'],
                );
            }
        });

        Cache::put(self::syncKey($day), now()->toIso8601String(), now()->addDays(40));

        return ['staff' => count($engagements), 'orders' => $orders->count(), 'delivered' => $delivered];
    }

    /**
     * Save the orders delivered on $day for the lead fallback (used when the
     * retention API is down). Orders the retention API already gave are left
     * as they are, since those carry its consumption days.
     */
    public function syncDelivered(CarbonImmutable $day): int
    {
        $catalog = new ProductCatalog;
        $orders = collect($this->client->deliveredOrders($day))->filter(fn (array $order) => $order['phone_number'] !== '' && $order['items']);
        $fromRetentionApi = DeliveredOrder::whereIn('order_id', $orders->pluck('order_id'))
            ->where('source', DeliveredOrder::SOURCE_SHECOM)->pluck('order_id')->flip();
        $now = now();

        $rows = $orders->reject(fn (array $order) => isset($fromRetentionApi[$order['order_id']]))->map(function (array $order) use ($catalog, $day, $now) {
            // The tracked product: the first item that matches a Product Consumption product, else the first item.
            $items = collect($order['items']);
            $main = $items->first(fn (array $item) => $catalog->match($item['name'])) ?? $items->first();
            $product = $catalog->match($main['name']);

            return [
                'order_id' => $order['order_id'],
                'tracking_number' => $order['tracking_number'],
                'customer_name' => $order['customer_name'] ?: 'Unknown',
                'phone_number' => $order['phone_number'],
                'product_raw' => $main['name'],
                // All units of that product in the order.
                'qty' => $product
                    ? $items->filter(fn (array $item) => $catalog->match($item['name'])?->is($product))->sum('qty')
                    : $main['qty'],
                'delivered_date' => $day->toDateString(),
                'consumption_days_per_unit' => null,
                'source' => DeliveredOrder::SOURCE_PANCAKE,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        })->values();

        foreach ($rows->chunk(500) as $chunk) {
            DeliveredOrder::upsert($chunk->values()->all(), ['order_id'], [
                'tracking_number', 'customer_name', 'phone_number', 'product_raw', 'qty', 'delivered_date', 'updated_at',
            ]);
        }

        return $rows->count();
    }

    /**
     * Sync $day unless it was synced within $maxAgeMinutes, or another sync
     * of it is running. Returns null when nothing was synced.
     */
    public function syncIfStale(CarbonImmutable $day, int $maxAgeMinutes = 60): ?array
    {
        if (! self::isStale($day, $maxAgeMinutes)) {
            return null;
        }

        return Cache::lock('pancake-sync-'.$day->toDateString(), 300)
            ->get(fn () => $this->sync($day)) ?: null;
    }

    /**
     * Sync $day after the response is sent (a full day takes minutes). Failures
     * are logged, and pages keep showing the last good sync time.
     */
    public function syncLater(CarbonImmutable $day, bool $force = false): void
    {
        defer(function () use ($day, $force) {
            try {
                $this->syncIfStale($day, $force ? 0 : 60);
            } catch (Throwable $e) {
                Log::warning('Pancake sync failed', ['date' => $day->toDateString(), 'message' => $e->getMessage()]);
            }
        }, 'pancake-sync-'.$day->toDateString());
    }

    public static function lastSync(CarbonImmutable $day): ?CarbonImmutable
    {
        $at = Cache::get(self::syncKey($day));

        return $at ? CarbonImmutable::parse($at) : null;
    }

    /**
     * Whether a sync of $day is in progress (a full day takes a few minutes).
     */
    public static function isRunning(CarbonImmutable $day): bool
    {
        return Cache::has(self::runningKey($day));
    }

    /**
     * Whether $day was last synced over $maxAgeMinutes ago, or never.
     */
    public static function isStale(CarbonImmutable $day, int $maxAgeMinutes = 60): bool
    {
        $last = self::lastSync($day);

        return ! $last || $last->lessThanOrEqualTo(now()->subMinutes($maxAgeMinutes));
    }

    private static function runningKey(CarbonImmutable $day): string
    {
        return 'pancake.syncing.'.$day->toDateString();
    }

    private static function syncKey(CarbonImmutable $day): string
    {
        return 'pancake.sync.'.$day->toDateString();
    }

    /**
     * The order's seller is the staff member it is assigned to, or whoever created it.
     */
    private function toOrder(array $order): ?array
    {
        // display_id is the shop's order number (the one Shecom's order_id uses); with a user
        // access token, id is Pancake's internal id instead.
        $orderId = $order['display_id'] ?? $order['id'] ?? null;

        if (empty($orderId) || empty($order['inserted_at'])) {
            return null;
        }

        // Pancake sends inserted_at in UTC without an offset.
        $orderedAt = CarbonImmutable::parse($order['inserted_at'], 'UTC');
        $seller = $order['assigning_seller'] ?? $order['creator'] ?? null;
        $phone = $order['bill_phone_number'] ?? ($order['customer']['phone_numbers'][0] ?? null);
        $phoneKey = $phone ? LeadGenerator::normalizePhone((string) $phone) : '';
        // POS order tags come as ids ([398, 17, …]); tolerate {id, name} objects too.
        $tagIds = collect($order['tags'] ?? [])
            ->map(fn ($tag) => (int) (is_array($tag) ? ($tag['id'] ?? 0) : $tag))
            ->filter()->values()->all();

        return [
            'pancake_order_id' => (string) $orderId,
            'ordered_on' => $orderedAt->setTimezone(config('segmentation.timezone'))->toDateString(),
            'ordered_at' => $orderedAt->toDateTimeString(),
            'seller_pancake_id' => $seller['id'] ?? null,
            'seller_name' => isset($seller['name']) ? PancakeEngagement::staffKey($seller['name']) : null,
            'customer_name' => $order['bill_full_name'] ?? ($order['customer']['name'] ?? null),
            'phone_number' => $phone,
            'phone_key' => $phoneKey !== '' ? $phoneKey : null,
            'status' => isset($order['status']) ? (int) $order['status'] : null,
            'status_name' => $order['status_name'] ?? null,
            'total_price' => (float) ($order['total_price'] ?? 0),
            'page_name' => $order['account_name'] ?? null,
            // upsert() skips model casts, so the list is stored as JSON here.
            'tags' => json_encode($tagIds),
            'conversion_type' => PancakeOrder::conversionTypeFor($tagIds),
        ];
    }
}
