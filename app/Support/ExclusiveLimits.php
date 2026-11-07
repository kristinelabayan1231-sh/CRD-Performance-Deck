<?php

namespace App\Support;

use App\Models\ExclusiveLeadLimit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The Exclusive Lead Tracker's limits on a real date: the latest set in Settings starting by then, so a change holds to
 * the end of its month and a month with none of its own keeps the last one. Before any is set, config/exclusive_leads.
 */
class ExclusiveLimits
{
    /**
     * @return array{daily_leads: int, month_target: int, orders_per_day: int}
     */
    public static function on(CarbonImmutable $realDay): array
    {
        $date = $realDay->toDateString();
        $limit = self::all()->first(fn (ExclusiveLeadLimit $limit) => $limit->effective_from->toDateString() <= $date);

        return [
            'daily_leads' => $limit?->daily_leads ?? (int) config('exclusive_leads.daily_quota'),
            'month_target' => $limit?->month_target ?? (int) config('exclusive_leads.month_target'),
            'orders_per_day' => $limit?->orders_per_day ?? (int) config('exclusive_leads.confirmed_orders_per_day'),
        ];
    }

    /**
     * Latest start first.
     *
     * @return Collection<int, ExclusiveLeadLimit>
     */
    private static function all(): Collection
    {
        return once(fn () => ExclusiveLeadLimit::orderByDesc('effective_from')->get());
    }
}
