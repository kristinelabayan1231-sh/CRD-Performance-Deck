<?php

namespace App\Services;

use App\Models\PancakeConversation;
use App\Models\PancakeOrder;
use App\Models\PancakePage;
use App\Models\PcReportRow;
use App\Support\ProductGroups;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * PC Report's Total Inquiries: the page's conversations created in the row's Creation/Update Time
 * with a phone number, whose customer has a Delivered order and no order that is New, Confirmed,
 * Shipped, Returning, Returned, Canceled, Deleted, Packing, Waiting for pickup, From Webcake or
 * Restocking, nor one tagged CRD - SEGMENTATION, and without the day's "CRD - OCT 14" conversation tag.
 * Orders are matched to the conversation by phone number.
 */
class PcInquiries
{
    /** Pancake POS status the customer must have an order in: Delivered. */
    public const INCLUDED_STATUS = 3;

    /**
     * Pancake POS statuses no order of the customer may be in: 0 New, 1 Confirmed, 2 Shipped, 4 Returning,
     * 5 Returned, 6 Canceled, 7 Deleted, 8 Packing, 9 Waiting for pickup, 11 Restocking, 17 From Webcake.
     */
    public const EXCLUDED_STATUSES = [0, 1, 2, 4, 5, 6, 7, 8, 9, 11, 17];

    /** Days one change request covers (Pancake allows under a month). */
    private const CHANGE_DAYS = 25;

    /** Days one engagement request covers. */
    private const ENGAGEMENT_DAYS = 31;

    public function __construct(private PancakeClient $client) {}

    /**
     * The Pancake pages PC Report shows, keyed by upper-case page name.
     *
     * @return Collection<string, PancakePage>
     */
    public static function pages(): Collection
    {
        $names = collect(ProductGroups::all())->pluck('pages')->flatten()->map(fn (string $name) => strtoupper($name))->all();

        return PancakePage::active()->get()->toBase()
            ->keyBy(fn (PancakePage $page) => strtoupper($page->name))
            ->only($names);
    }

    /**
     * Fetch the conversations of a page with synced days that changed since the last run (tags added, numbers
     * given), so its saved days stay current. One small request per page; nothing for a page never synced.
     */
    public function refreshChanges(PancakePage $page): int
    {
        if ($page->conversations_synced_at === null) {
            return 0;
        }

        $now = CarbonImmutable::now();
        $changed = 0;
        for ($from = $page->conversations_synced_at->subMinutes(5); $from->lessThan($now); $from = $to) {
            $to = $from->addDays(self::CHANGE_DAYS)->min($now);
            $changed += $this->save($page, $this->client->conversations($page, $from, $to, byUpdate: true));
        }
        $page->update(['conversations_synced_at' => $now]);

        return $changed;
    }

    /**
     * Sync one row: fetch, a day at a time and newest first, its page's conversations created on the row's days that
     * aren't saved yet, then the page's Customer engagement for those days. $progress gets (days done, days in all)
     * after each day. A day is saved for the page, so another row covering it doesn't fetch it again.
     *
     * @param  callable(int, int): void  $progress
     */
    public function syncRow(PcReportRow $row, PancakePage $page, callable $progress): void
    {
        // From now on the page's changes are fetched every few minutes; the days below are fetched once.
        if ($page->conversations_synced_at === null) {
            $page->update(['conversations_synced_at' => CarbonImmutable::now()]);
        }

        $days = self::days($row);
        $missing = array_reverse(self::missingDays($page, $days));
        $done = count($days) - count($missing);
        $progress($done, count($days));
        $timezone = config('segmentation.timezone');

        foreach ($missing as $day) {
            $start = CarbonImmutable::parse($day, $timezone);
            $this->save($page, $this->client->conversations($page, $start, $start->endOfDay()));
            $page->update(['conversation_days' => collect($page->conversation_days ?? [])->push($day)->unique()->sort()->values()->all()]);
            $progress(++$done, count($days));
        }

        $row->update(['engagements' => $this->engagements($page, $days), 'synced_at' => CarbonImmutable::now()]);
    }

    /**
     * The page's Customer engagement (Pancake → Analytics, the "total" series) summed over $days, a month at a time.
     *
     * @param  list<string>  $days
     */
    public function engagements(PancakePage $page, array $days): int
    {
        if ($days === []) {
            return 0;
        }

        $timezone = config('segmentation.timezone');
        $last = CarbonImmutable::parse(end($days), $timezone);
        $total = 0;
        for ($from = CarbonImmutable::parse($days[0], $timezone); $from->lessThanOrEqualTo($last); $from = $to->addDay()) {
            $to = $from->addDays(self::ENGAGEMENT_DAYS - 1)->min($last);
            $total += $this->client->pageEngagements($page, $from, $to);
        }

        return $total;
    }

    /**
     * The row's days: from the first of its first month to the end of its last month (to today when it has no end, or
     * when that's later than today), oldest first.
     *
     * @return list<string>
     */
    public static function days(PcReportRow $row): array
    {
        if ($row->from_month === null) {
            return [];
        }

        $today = WorkingDate::realToday();
        $last = ($row->to_month?->endOfMonth() ?? $today)->min($today);
        $days = [];
        for ($day = $row->from_month->startOfMonth(); $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
            $days[] = $day->toDateString();
        }

        return $days;
    }

    /**
     * Of $days, the ones whose conversations aren't saved for the page yet.
     *
     * @param  list<string>  $days
     * @return list<string>
     */
    public static function missingDays(PancakePage $page, array $days): array
    {
        return array_values(array_diff($days, $page->conversation_days ?? []));
    }

    /**
     * Total Inquiries of the page for conversations created from $fromMonth to the end of $toMonth, on $day.
     */
    public function count(PancakePage $page, CarbonImmutable $fromMonth, CarbonImmutable $toMonth, CarbonImmutable $day): int
    {
        // Worked out again after every change fetch and every newly synced day.
        $key = implode('.', ['pc-inquiries', $page->id, $fromMonth->format('Y-m'), $toMonth->format('Y-m'), $day->toDateString(), $page->conversations_synced_at?->getTimestamp(), count($page->conversation_days ?? [])]);

        return Cache::remember($key, now()->addMinutes(10), function () use ($page, $fromMonth, $toMonth, $day) {
            $timezone = config('segmentation.timezone');
            $todayTag = 'CRD - '.strtoupper($day->format('M j'));

            $conversations = PancakeConversation::where('pancake_page_id', $page->id)
                ->whereBetween('started_at', [
                    CarbonImmutable::parse($fromMonth->format('Y-m-01'), $timezone)->utc(),
                    CarbonImmutable::parse($toMonth->format('Y-m-01'), $timezone)->endOfMonth()->utc(),
                ])
                ->get(['phone_keys', 'tags'])
                ->reject(fn (PancakeConversation $conversation) => in_array($todayTag, $conversation->tags, true));

            $customers = self::customers($conversations->pluck('phone_keys')->flatten()->unique()->values());

            return $conversations->filter(function (PancakeConversation $conversation) use ($customers) {
                $orders = collect($conversation->phone_keys)->map(fn (string $key) => $customers[$key] ?? null)->filter();

                return $orders->contains('delivered', true) && ! $orders->contains('excluded', true);
            })->count();
        });
    }

    /**
     * Per phone number: whether any of its orders is Delivered, and whether any is in an excluded
     * status or tagged CRD - SEGMENTATION.
     *
     * @param  Collection<int, string>  $phoneKeys
     * @return array<string, array{delivered: bool, excluded: bool}>
     */
    private static function customers(Collection $phoneKeys): array
    {
        $segmentationTag = (int) config('segmentation.conversion_tags.segmentation');
        $customers = [];

        foreach ($phoneKeys->chunk(1000) as $chunk) {
            PancakeOrder::whereIn('phone_key', $chunk->all())->get(['phone_key', 'status', 'tags'])->each(function (PancakeOrder $order) use (&$customers, $segmentationTag) {
                $customer = $customers[$order->phone_key] ?? ['delivered' => false, 'excluded' => false];
                $customer['delivered'] = $customer['delivered'] || $order->status === self::INCLUDED_STATUS;
                $customer['excluded'] = $customer['excluded']
                    || in_array($order->status, self::EXCLUDED_STATUSES, true)
                    || in_array($segmentationTag, array_map('intval', $order->tags ?? []), true);
                $customers[$order->phone_key] = $customer;
            });
        }

        return $customers;
    }

    /**
     * Insert or update the conversations by their Pancake id.
     *
     * @param  list<array{conversation_id: string, started_at: string, phone_keys: list<string>, tags: list<string>}>  $conversations
     */
    private function save(PancakePage $page, array $conversations): int
    {
        $now = now();

        foreach (array_chunk($conversations, 500) as $chunk) {
            PancakeConversation::upsert(
                array_map(fn (array $conversation) => [
                    ...$conversation,
                    'pancake_page_id' => $page->id,
                    // upsert() skips model casts, so the lists are stored as JSON here.
                    'phone_keys' => json_encode($conversation['phone_keys']),
                    'tags' => json_encode($conversation['tags']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk),
                ['pancake_page_id', 'conversation_id'],
                ['started_at', 'phone_keys', 'tags', 'updated_at'],
            );
        }

        return count($conversations);
    }
}
