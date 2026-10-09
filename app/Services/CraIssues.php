<?php

namespace App\Services;

use App\Models\PancakeOrder;
use App\Models\User;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Problems in the CRAs' orders that make their numbers wrong, shown in the header.
 *
 * - Untagged order: a CRA's own Pancake order (not canceled/deleted) without CRD - BROADCAST or CRD - SEGMENTATION,
 *   so it is left out of confirmed orders and gross sales.
 * - Both CRD tags: counted as segmentation; one tag should come off.
 * - No Pancake account: the CRA's orders and chats can't be matched at all.
 *
 * Covers this month up to today (real date), using each order's latest tags.
 */
class CraIssues
{
    public const UNTAGGED = 'untagged';

    public const BOTH_TAGS = 'both_tags';

    public const NO_ACCOUNT = 'no_account';

    public const LABELS = [
        self::UNTAGGED => 'No CRD tag',
        self::BOTH_TAGS => 'Both CRD tags',
        self::NO_ACCOUNT => 'No Pancake account',
    ];

    public const FIXES = [
        self::UNTAGGED => 'Add CRD - BROADCAST or CRD - SEGMENTATION to the order in Pancake. Until then it is left out of confirmed orders and gross sales.',
        self::BOTH_TAGS => 'Keep only one CRD tag on the order in Pancake. It is counted as segmentation for now.',
        self::NO_ACCOUNT => 'Set the CRA\'s Pancake account in Segmentation Productivity, or their orders and chats can\'t be counted.',
    ];

    /**
     * The CRAs whose issues a user sees: everyone for supervisors, themselves for a CRA, nobody otherwise.
     *
     * @return Collection<int, User>
     */
    public static function crasFor(User $user): Collection
    {
        $cras = LeadGenerator::cras();

        return $user->can('productivity.view_all') ? $cras : $cras->where('id', $user->id)->values();
    }

    /**
     * @param  Collection<int, User>  $cras
     * @return Collection<int, array{type: string, cra: User, day: ?CarbonImmutable, order_id: ?string, customer: ?string, amount: ?float}>
     */
    public function for(Collection $cras, ?CarbonImmutable $today = null): Collection
    {
        $today ??= WorkingDate::realToday();
        $accounts = ConversionBreakdown::accounts($cras);
        $byId = $cras->keyBy('id');

        $issues = $cras->filter(fn (User $cra) => ! $cra->pancake_name)
            ->map(fn (User $cra) => ['type' => self::NO_ACCOUNT, 'cra' => $cra, 'day' => null, 'order_id' => null, 'customer' => null, 'amount' => null])
            ->values();

        if ($accounts->isEmpty()) {
            return $issues;
        }

        $both = [config('segmentation.conversion_tags.broadcast'), config('segmentation.conversion_tags.segmentation')];

        $orders = PancakeOrder::counted()
            ->whereIn('seller_name', $accounts->keys())
            ->whereDate('ordered_on', '>=', $today->startOfMonth())->whereDate('ordered_on', '<=', $today)
            ->where(fn ($q) => $q->whereNull('conversion_type')
                ->orWhere(fn ($q) => $q->where('conversion_type', PancakeOrder::SEGMENTATION)
                    ->whereJsonContains('tags', $both[0])->whereJsonContains('tags', $both[1])))
            ->orderByDesc('ordered_on')->orderBy('seller_name')->orderBy('id')
            ->get(['pancake_order_id', 'ordered_on', 'seller_name', 'customer_name', 'conversion_type', 'total_price', 'shecom_sales']);

        return $issues->concat($orders->map(fn (PancakeOrder $order) => [
            'type' => $order->conversion_type === null ? self::UNTAGGED : self::BOTH_TAGS,
            'cra' => $byId[$accounts[$order->seller_name]],
            'day' => $order->ordered_on,
            'order_id' => $order->pancake_order_id,
            'customer' => $order->customer_name,
            'amount' => $order->sales(),
        ]))->values();
    }
}
