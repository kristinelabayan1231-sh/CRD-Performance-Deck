<?php

namespace App\Services;

use App\Models\PancakeEngagement;
use App\Models\PancakeOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PancakeSync
{
    public function __construct(private PancakeClient $client) {}

    /**
     * Copy one day's chat engagements and POS orders from Pancake. Rows are
     * updated in place by their Pancake id; nothing is deleted.
     *
     * @return array{staff: int, orders: int}
     */
    public function sync(CarbonImmutable $day): array
    {
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
                        'phone_key', 'status', 'status_name', 'total_price', 'page_name', 'updated_at'],
                );
            }
        });

        Cache::put(self::syncKey($day), now()->toIso8601String(), now()->addDays(40));

        return ['staff' => count($engagements), 'orders' => $orders->count()];
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
        ];
    }
}
