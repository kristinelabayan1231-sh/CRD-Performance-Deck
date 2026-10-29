<?php

namespace App\Services;

use App\Models\PancakeOrder;
use App\Models\PancakePage;
use App\Models\User;
use App\Support\ProductGroups;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Orders the Daily I.S. and the Report Summary / CVR (Conversion Breakdown) count differently, for supervisors.
 *
 * - Page not in the Daily I.S.: a CRA's tagged order on a page that isn't in a product group that day (Settings →
 *   Pancake Pages), so the Daily I.S. leaves it out while the Report Summary counts it.
 * - No CRD tag: an order on a product page without CRD - BROADCAST or CRD - SEGMENTATION, so the Daily I.S. counts it
 *   while the Report Summary leaves it out.
 *
 * Covers the month of the day shown, from the 1st up to that day.
 */
class DailyIsConflicts
{
    public const PAGE = 'page';

    public const UNTAGGED = 'untagged';

    public const LABELS = [
        self::PAGE => 'Page not in the Daily I.S.',
        self::UNTAGGED => 'No CRD tag',
    ];

    public const FIXES = [
        self::PAGE => 'Counted in the Report Summary and CVR, left out of the Daily I.S. Add the page to its product group in Settings → Pancake Pages (or move its starting date back).',
        self::UNTAGGED => 'Counted in the Daily I.S., left out of the Report Summary and CVR. Add CRD - BROADCAST or CRD - SEGMENTATION to the order in Pancake.',
    ];

    public function __construct(private PageOrders $pageOrders) {}

    /**
     * @param  Collection<int, User>  $cras
     * @return Collection<int, array{type: string, cra: User, day: CarbonImmutable, order_id: string, customer: ?string, page: ?string, note: ?string, amount: float}>
     */
    public function for(Collection $cras, CarbonImmutable $day): Collection
    {
        $from = $day->startOfMonth();
        $byId = $cras->keyBy('id');

        $inStatement = [];
        for ($d = $from; $d->lte($day); $d = $d->addDay()) {
            $pages = collect(ProductGroups::on($d))->pluck('pages')->flatten()->all();
            foreach ($this->pageOrders->forDay($cras, $pages, $d)['orders'] as $order) {
                $inStatement[$order['id']] = $order['cra'];
            }
        }

        $accounts = ConversionBreakdown::accounts($cras);
        $inBreakdown = $accounts->isEmpty() ? collect() : ConversionBreakdown::taggedOrders($accounts, $from, $day)->get()
            ->mapWithKeys(fn (PancakeOrder $order) => [$order->id => ConversionBreakdown::craFor($order, $accounts)]);

        $missing = $inBreakdown->diffKeys($inStatement);
        $untagged = collect($inStatement)->diffKeys($inBreakdown);
        $orders = PancakeOrder::whereIn('id', $missing->keys()->concat($untagged->keys()))
            ->get(['id', 'pancake_order_id', 'ordered_on', 'customer_name', 'page_id', 'page_name', 'conversion_type', 'total_price', 'shecom_sales'])
            ->keyBy('id');
        $startsLater = $this->startsLater();

        $issue = fn (string $type, int $id, int $craId, ?string $note) => [
            'type' => $type,
            'cra' => $byId[$craId],
            'day' => $orders[$id]->ordered_on,
            'order_id' => $orders[$id]->pancake_order_id,
            'customer' => $orders[$id]->customer_name,
            'page' => $orders[$id]->page_name,
            'note' => $note,
            'amount' => $orders[$id]->sales(),
        ];

        return $missing->filter(fn (?int $craId) => $craId !== null && $byId->has($craId))
            ->map(fn (int $craId, int $id) => $issue(self::PAGE, $id, $craId, $startsLater($orders[$id])))
            ->concat($untagged->filter(fn (int $craId, int $id) => $orders[$id]->conversion_type === null)
                ->map(fn (int $craId, int $id) => $issue(self::UNTAGGED, $id, $craId, null)))
            ->sortByDesc(fn (array $issue) => $issue['day']->toDateString().'|'.$issue['order_id'])
            ->values();
    }

    /**
     * Why an order's page isn't counted on its day: "In <group> from <date>" when the page is in a group that starts
     * later, else "Not in a product group".
     *
     * @return Closure(PancakeOrder): string
     */
    private function startsLater(): Closure
    {
        $groups = ProductGroups::all();
        $pageOf = PageOrders::pageMatcher(collect($groups)->pluck('pages')->flatten()->all());
        $countsFrom = PancakePage::whereNotNull('counts_from')->pluck('counts_from', 'name')
            ->mapWithKeys(fn ($date, string $name) => [Str::lower($name) => $date->toDateString()]);

        return function (PancakeOrder $order) use ($groups, $pageOf, $countsFrom) {
            $page = $pageOf($order);
            $group = $page === null ? null : collect($groups)->first(fn (array $group) => in_array($page, $group['pages'], true));

            if ($group === null) {
                return 'Not in a product group';
            }

            $starts = max($group['added_on'] ?? '', $countsFrom[Str::lower($page)] ?? '');

            return $starts === '' ? "In {$group['name']}" : "In {$group['name']} from ".CarbonImmutable::parse($starts)->format('M j');
        };
    }
}
