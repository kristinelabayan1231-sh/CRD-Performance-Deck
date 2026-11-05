<?php

namespace App\Support;

use App\Models\LogisticsOrder;
use App\Models\PancakeEngagement;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\CustomerDatabase;
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
 * - Each CRA's other accounts (set here, default config customers.cra_account_aliases): count as
 *   that CRA's own account everywhere it is used.
 */
class PancakeAccounts
{
    public const CRD = 'customers.crd_accounts';

    /** Each CRA's other accounts: other account => the CRA's own account. */
    public const ALIASES = 'customers.cra_account_aliases';

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
        CustomerDatabase::flushCache();

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
            ->inCraOrder()
            ->get();
    }

    /**
     * The CRAs' other accounts as entered: other account => the CRA's own account.
     *
     * @return array<string, string>
     */
    public static function aliases(): array
    {
        $saved = Setting::value(self::ALIASES);

        return $saved === null ? config('customers.cra_account_aliases', []) : json_decode($saved, true);
    }

    /**
     * Each CRA's other accounts as entered, by CRA id.
     *
     * @param  Collection<int, User>  $cras
     * @return array<int, list<string>>
     */
    public static function aliasesByCra(Collection $cras): array
    {
        $aliases = collect(self::aliases());

        return $cras->mapWithKeys(fn (User $cra) => [$cra->id => $cra->pancake_name
            ? $aliases->filter(fn (string $account) => PancakeEngagement::staffKey($account) === PancakeEngagement::staffKey($cra->pancake_name))->keys()->values()->all()
            : []])->all();
    }

    /**
     * Save the other accounts of these CRAs (blanks, repeats and their own account dropped). Other accounts of
     * CRAs not in $cras are kept.
     *
     * @param  Collection<int, User>  $cras
     * @param  array<int, list<string|null>>  $names  by CRA id
     */
    public static function saveAliases(Collection $cras, array $names, User $by): void
    {
        $owners = $cras->map(fn (User $cra) => PancakeEngagement::staffKey($cra->pancake_name))->all();
        $aliases = collect(self::aliases())->reject(fn (string $account) => in_array(PancakeEngagement::staffKey($account), $owners, true));

        foreach ($cras as $cra) {
            $own = PancakeEngagement::staffKey($cra->pancake_name);

            collect($names[$cra->id] ?? [])
                ->map(fn (?string $name) => trim(preg_replace('/\s+/', ' ', (string) $name)))
                ->filter(fn (string $name) => $name !== '' && PancakeEngagement::staffKey($name) !== $own)
                ->unique(PancakeEngagement::staffKey(...))
                ->each(function (string $name) use ($aliases, $cra) {
                    $aliases[$name] = $cra->pancake_name;
                });
        }

        Setting::put(self::ALIASES, json_encode($aliases->all()), $by);
        CustomerDatabase::flushCache();
    }

    /**
     * A CRA's own account switched in User Access: the previous account becomes one of their other accounts and
     * their other accounts follow to the new one, so their earlier orders and chats still count as theirs.
     */
    public static function switchOwnAccount(User $cra, ?string $previous, User $by): void
    {
        $old = PancakeEngagement::staffKey((string) $previous);
        $new = PancakeEngagement::staffKey((string) $cra->pancake_name);

        if ($old === '' || $new === '' || $old === $new || $cra->role?->slug !== Role::CRA) {
            return;
        }

        $aliases = collect(self::aliases())
            ->map(fn (string $account) => PancakeEngagement::staffKey($account) === $old ? $cra->pancake_name : $account)
            ->reject(fn (string $account, string $alias) => PancakeEngagement::staffKey($alias) === $new || PancakeEngagement::staffKey($alias) === $old);
        $aliases[$previous] = $cra->pancake_name;

        Setting::put(self::ALIASES, json_encode($aliases->all()), $by);
        CustomerDatabase::flushCache();
    }

    /**
     * A CRA's Pancake accounts as staff keys: their own (User Access) first, then their other accounts
     * (aliases(), e.g. LHEA GATDULA for CRD LHEI). Empty when no account is set.
     *
     * @return list<string>
     */
    public static function keysOf(User $cra): array
    {
        if (! $cra->pancake_name) {
            return [];
        }

        $own = PancakeEngagement::staffKey($cra->pancake_name);

        return collect(self::aliases())
            ->filter(fn (string $account) => PancakeEngagement::staffKey($account) === $own)
            ->keys()->map(fn (string $alias) => PancakeEngagement::staffKey($alias))
            ->prepend($own)->unique()->values()->all();
    }

    /**
     * The account a seller key counts as: the CRA's own account when it is one of their other accounts
     * (aliases()), else the key itself.
     */
    public static function ownerKey(string $sellerKey): string
    {
        foreach (self::aliases() as $alias => $account) {
            if (PancakeEngagement::staffKey($alias) === $sellerKey) {
                return PancakeEngagement::staffKey($account);
            }
        }

        return $sellerKey;
    }

    /**
     * Seller key => CRA for these CRAs: each one's own account and their other accounts (keysOf()).
     *
     * @param  Collection<int, User>  $cras
     * @return Collection<string, User>
     */
    public static function craBySeller(Collection $cras): Collection
    {
        return $cras->flatMap(fn (User $cra) => collect(self::keysOf($cra))->mapWithKeys(fn (string $key) => [$key => $cra]));
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
