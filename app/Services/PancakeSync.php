<?php

namespace App\Services;

use App\Models\DeliveredOrder;
use App\Models\LogisticsOrder;
use App\Models\PancakeEngagement;
use App\Models\PancakeOrder;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

use function Illuminate\Support\defer;

class PancakeSync
{
    public function __construct(private PancakeClient $client, private ShecomClient $shecom) {}

    /** How old (minutes) a day's sync may be before pages and the dashboard refresh it. */
    public const FRESH_MINUTES = 10;

    /** How often (seconds) a page visit may re-check the header's flagged orders in Pancake. */
    public const ISSUES_RECHECK_SECONDS = 120;

    /**
     * Copy one day's chat engagements and POS orders from Pancake. Rows are
     * updated in place by their Pancake id; nothing is deleted.
     *
     * @return array{staff: int, orders: int, delivered: int, sales: int, engagement_error: ?string}
     */
    public function sync(CarbonImmutable $day): array
    {
        // The lead fallback's orders; a failure here mustn't stop the engagements and orders below.
        $delivered = rescue(fn () => $this->syncDelivered($day), 0);

        $day = $day->startOfDay();
        Cache::put(self::runningKey($day), true, now()->addMinutes(15));
        $engagementError = null;

        try {
            // One page's chat statistics failing mustn't hold back the orders and tags: the day keeps its last good engagements.
            try {
                $engagements = $this->client->engagements($day);
            } catch (Throwable $e) {
                $engagements = [];
                $engagementError = $e->getMessage();
                Log::warning('Pancake engagements failed; orders still synced', ['date' => $day->toDateString(), 'message' => $engagementError]);
            }

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

            $this->saveOrders($orders, $now);
        });

        // Gross sales without the child (TSD) row; Pancake's totals stay in use if Shecom is down.
        $sales = rescue(fn () => $this->syncSales($day, $day), 0);

        // The header's flagged orders, looked up one by one (seconds), so a fixed tag clears without waiting on the list below.
        if ($day->isSameDay(WorkingDate::realToday())) {
            rescue(fn () => $this->recheckIssues());
        }

        // Older orders whose CRD tag or status changed on this day (tags are often added later).
        rescue(fn () => $this->refreshTags($day));

        Cache::put(self::syncKey($day), now()->toIso8601String(), now()->addDays(40));

        return ['staff' => count($engagements), 'orders' => $orders->count(), 'delivered' => $delivered, 'sales' => $sales, 'engagement_error' => $engagementError];
    }

    /**
     * Copy only the POS orders created on $day (with their items), for backfilling the
     * Customer Database: no engagements, tags or sales.
     *
     * @return int orders saved
     */
    public function syncOrders(CarbonImmutable $day): int
    {
        $orders = collect($this->client->orders($day->startOfDay()))->map(fn (array $order) => $this->toOrder($order))->filter()->values();

        DB::transaction(fn () => $this->saveOrders($orders, now()));

        return $orders->count();
    }

    /**
     * Save the POS orders delivered on $day as Customer Database deliveries (for days the
     * logistics report doesn't cover), with their amounts and items. CRD = sold by a CRD account.
     *
     * @return int deliveries saved
     */
    public function syncDeliveredCustomers(CarbonImmutable $day): int
    {
        $orders = collect($this->client->deliveredOn($day->startOfDay()))->map(fn (array $order) => $this->toOrder($order))->filter()->values();

        DB::transaction(fn () => $this->saveOrders($orders, now()));

        $crdAccounts = PancakeOrder::crdAccounts();

        return LogisticsOrder::remember($orders->map(fn (array $order) => [
            'order_id' => $order['pancake_order_id'],
            'team' => PancakeOrder::isCrdAccount($order['seller_name'], $crdAccounts) ? LogisticsOrder::TEAM_CRD : LogisticsOrder::TEAM_FSD,
            'source' => LogisticsOrder::SOURCE_POS,
            'customer_name' => trim((string) $order['customer_name']) ?: 'Unknown',
            'phone_number' => (string) $order['phone_number'],
            'product' => json_decode($order['items'], true)[0]['name'] ?? '',
            'qty' => null,
            'delivered_date' => $day->toDateString(),
        ])->all());
    }

    /**
     * Fetch these orders (order numbers) from Pancake and save them, e.g. delivered orders
     * the backfill hasn't reached yet.
     *
     * @param  list<string>  $numbers
     * @return int orders saved
     */
    public function syncOrdersByNumber(array $numbers): int
    {
        $orders = collect($this->client->ordersByNumber($numbers, full: true))->map(fn (array $order) => $this->toOrder($order))->filter()->values();

        DB::transaction(fn () => $this->saveOrders($orders, now()));

        return $orders->count();
    }

    /**
     * Insert or update orders by their Pancake id.
     *
     * @param  Collection<int, array<string, mixed>>  $orders
     */
    private function saveOrders(Collection $orders, CarbonInterface $now): void
    {
        foreach ($orders->chunk(200) as $chunk) {
            PancakeOrder::upsert(
                $chunk->map(fn (array $row) => [...$row, 'created_at' => $now, 'updated_at' => $now])->all(),
                ['pancake_order_id'],
                ['ordered_on', 'ordered_at', 'seller_pancake_id', 'seller_name', 'customer_name', 'phone_number',
                    'phone_key', 'status', 'status_name', 'total_price', 'items', 'page_name', 'tags', 'conversion_type', 'updated_at'],
            );
        }
    }

    /**
     * Update tags and status on saved orders that Pancake shows as changed on $day: only the changes
     * since the last refresh of $day (with some overlap), since a busy day has over a thousand.
     *
     * @return int orders updated
     */
    public function refreshTags(CarbonImmutable $day): int
    {
        $last = Cache::get(self::tagsKey($day));
        $startedAt = now();

        $updated = $this->applyChanges($this->client->updatedOrders($day, $last ? CarbonImmutable::parse($last)->subMinutes(10) : null));

        Cache::put(self::tagsKey($day), $startedAt->toIso8601String(), now()->addDays(40));

        return $updated;
    }

    /**
     * Look up the orders the header flags this month (no CRD tag, or both) in Pancake again and save
     * their current tags and status.
     *
     * @return int orders updated
     */
    public function recheckIssues(): int
    {
        $numbers = app(CraIssues::class)->for(LeadGenerator::cras())->pluck('order_id')->filter()->unique()->values()->all();

        return $numbers ? $this->applyChanges($this->client->ordersByNumber($numbers)) : 0;
    }

    /**
     * Save Pancake's current tags and status on the saved orders among $orders. Each order keeps its
     * own tags, so a customer can be broadcast on one order and segmentation on the next.
     *
     * @param  list<array<string, mixed>>  $orders
     * @return int orders updated
     */
    private function applyChanges(array $orders): int
    {
        $changed = collect($orders)
            ->filter(fn (array $order) => ! empty($order['display_id'] ?? $order['id'] ?? null))
            ->mapWithKeys(function (array $order) {
                $tagIds = collect($order['tags'] ?? [])
                    ->map(fn ($tag) => (int) (is_array($tag) ? ($tag['id'] ?? 0) : $tag))
                    ->filter()->values()->all();

                return [(string) ($order['display_id'] ?? $order['id']) => [
                    'tags' => $tagIds,
                    'conversion_type' => PancakeOrder::conversionTypeFor($tagIds),
                    'status' => isset($order['status']) ? (int) $order['status'] : null,
                    'status_name' => $order['status_name'] ?? null,
                ]];
            });

        $updated = 0;

        foreach ($changed->keys()->chunk(500) as $ids) {
            PancakeOrder::whereIn('pancake_order_id', $ids->values())
                ->get(['id', 'pancake_order_id', 'tags', 'conversion_type', 'status', 'status_name'])
                ->each(function (PancakeOrder $order) use ($changed, &$updated) {
                    $fresh = $changed[$order->pancake_order_id];

                    if ($order->tags === $fresh['tags'] && $order->status === $fresh['status']) {
                        return;
                    }

                    $order->forceFill($fresh)->save();
                    $updated++;
                });
        }

        return $updated;
    }

    /**
     * Save Shecom's sales on the orders dated $from–$to. Shecom's order id is
     * Pancake's display_id, which pancake_order_id holds.
     *
     * @return int orders given a Shecom amount
     */
    public function syncSales(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $sales = collect($this->shecom->sales($from, $to));

        // One update per distinct amount; a day has only a few dozen.
        return $sales->keys()->groupBy(fn (string $id) => (string) $sales[$id])
            ->sum(fn ($ids, string $amount) => $ids->chunk(1000)->sum(
                fn ($chunk) => PancakeOrder::whereIn('pancake_order_id', $chunk->values())->update(['shecom_sales' => $amount])
            ));
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
    public function syncIfStale(CarbonImmutable $day, int $maxAgeMinutes = self::FRESH_MINUTES): ?array
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
            // The page is already sent: let the sync finish past the request time limit.
            set_time_limit(0);
            ignore_user_abort(true);

            try {
                $this->syncIfStale($day, $force ? 0 : self::FRESH_MINUTES);
            } catch (Throwable $e) {
                Log::warning('Pancake sync failed', ['date' => $day->toDateString(), 'message' => $e->getMessage()]);
            }
        }, 'pancake-sync-'.$day->toDateString());
    }

    /**
     * Re-check the header's flagged orders after the response is sent, at most every
     * ISSUES_RECHECK_SECONDS. Pages call it so fixed tags clear even when the scheduler
     * is paused (Render's free plan sleeps when idle).
     */
    public function recheckIssuesLater(): void
    {
        if (! Cache::add('pancake-issues-recheck', true, self::ISSUES_RECHECK_SECONDS)) {
            return;
        }

        defer(function () {
            set_time_limit(0);
            ignore_user_abort(true);

            try {
                $this->recheckIssues();
            } catch (Throwable $e) {
                Log::warning('Pancake order issues re-check failed', ['message' => $e->getMessage()]);
            }
        }, 'pancake-issues-recheck');
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
    public static function isStale(CarbonImmutable $day, int $maxAgeMinutes = self::FRESH_MINUTES): bool
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

    private static function tagsKey(CarbonImmutable $day): string
    {
        return 'pancake.tags.'.$day->toDateString();
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
            'items' => json_encode($order['items'] ?? []),
            'page_name' => $order['account_name'] ?? null,
            // upsert() skips model casts, so the list is stored as JSON here.
            'tags' => json_encode($tagIds),
            'conversion_type' => PancakeOrder::conversionTypeFor($tagIds),
        ];
    }
}
