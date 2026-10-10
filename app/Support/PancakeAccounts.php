<?php

namespace App\Support;

use App\Models\LogisticsOrder;
use App\Models\PancakeEngagement;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The two lists of Pancake seller accounts (Settings → Pancake Accounts):
 *
 * - CRD team accounts, current and past (set here, default config customers.crd_accounts):
 *   the Customer Database counts orders they sold as handled by a CRA.
 * - Each CRA's own account (User Access → Pancake account): credits sales, orders and
 *   conversion to that CRA across the deck; the Customer Database counts them too.
 */
class PancakeAccounts
{
    public const CRD = 'customers.crd_accounts';

    /** When the CRD team accounts last changed: recent customers checked before it get their earlier history checked again. */
    public const HISTORY_RECHECK_FROM = 'customers.history_recheck_from';

    /** Customers delivered within this many months before a change are checked again. */
    public const RECHECK_MONTHS = 1;

    /**
     * The CRD team accounts as entered.
     *
     * @return list<string>
     */
    public static function crd(): array
    {
        $saved = Setting::value(self::CRD);

        return $saved === null ? config('customers.crd_accounts') : json_decode($saved, true);
    }

    /**
     * The CRD team accounts as staff keys (upper case, single spaces), how seller names are matched.
     *
     * @return list<string>
     */
    public static function crdKeys(): array
    {
        return array_values(array_unique(array_map(PancakeEngagement::staffKey(...), self::crd())));
    }

    /**
     * Save the CRD team accounts (blanks and repeats dropped), relabel the Pancake POS deliveries
     * saved before the logistics report starts (CRD = sold by one of them) and have the customers
     * delivered in the month before checked again for earlier CRA orders.
     *
     * @param  list<string|null>  $names
     * @return int deliveries whose label changed
     */
    public static function saveCrd(array $names, User $by): int
    {
        $unique = collect($names)
            ->map(fn (?string $name) => trim(preg_replace('/\s+/', ' ', (string) $name)))
            ->filter()
            ->unique(PancakeEngagement::staffKey(...))
            ->values()
            ->all();

        Setting::put(self::CRD, json_encode($unique), $by);
        Setting::put(self::HISTORY_RECHECK_FROM, now()->toIso8601String(), $by);
        Cache::forget('customers.history_progress');

        return self::relabelPosDeliveries();
    }

    /**
     * CRA users with a Pancake account set in User Access.
     *
     * @return Collection<int, User>
     */
    public static function craUsers(): Collection
    {
        return User::whereHas('role', fn ($q) => $q->where('slug', Role::CRA))
            ->whereNotNull('pancake_name')
            ->orderByDesc('is_active')
            ->orderBy('pancake_name')
            ->get();
    }

    /**
     * The last change of the CRD team accounts: customers delivered in the month before it and
     * checked before it are due again; null = every check still counts.
     */
    public static function historyRecheckFrom(): ?CarbonImmutable
    {
        $value = Setting::value(self::HISTORY_RECHECK_FROM);

        return $value ? CarbonImmutable::parse($value) : null;
    }

    /**
     * @return int deliveries whose label changed
     */
    private static function relabelPosDeliveries(): int
    {
        $keys = self::crdKeys();
        $soldByCrd = fn ($q) => $q->select('pancake_order_id')->from('pancake_orders')->whereIn('seller_name', $keys ?: ['']);

        return DB::transaction(fn () => LogisticsOrder::where('source', LogisticsOrder::SOURCE_POS)
            ->where('team', '!=', LogisticsOrder::TEAM_CRD)->whereIn('order_id', $soldByCrd)
            ->update(['team' => LogisticsOrder::TEAM_CRD])
            + LogisticsOrder::where('source', LogisticsOrder::SOURCE_POS)
                ->where('team', LogisticsOrder::TEAM_CRD)->whereNotIn('order_id', $soldByCrd)
                ->update(['team' => LogisticsOrder::TEAM_FSD]));
    }
}
