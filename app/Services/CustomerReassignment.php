<?php

namespace App\Services;

use App\Models\CustomerAccount;
use App\Models\CustomerClaim;
use App\Models\CustomerLink;
use App\Models\ExclusiveListLead;
use App\Models\Lead;
use App\Models\LogisticsOrder;
use App\Models\PancakeOrder;
use App\Models\User;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * CRA Dashboard → Up for reassignment. A CRA's customer (High AOV and VIP too) who hasn't ordered again within
 * config customers.reassign_after_days of their last delivery is open: any other CRA can claim them, and the claim
 * makes the customer theirs (CustomerOwners) until they too go that long without a reorder. The days count from the
 * claim when there is one. Only deliveries from config customers.reassign_from on (the day the rule started) count: a
 * customer last delivered before it never opens unless claimed, so the list grows day by day instead of all at once.
 * Dates are real dates (deliveries and orders), not lead days.
 */
class CustomerReassignment
{
    private const CACHE_KEY = 'customers.reassignment.pool';

    /** Days before opening that a CRA's own customer shows as opening soon. */
    public const SOON_DAYS = 7;

    public function __construct(private CustomerOwners $owners, private HighValueCustomers $highValue, private CustomerDatabase $customers) {}

    public static function afterDays(): int
    {
        return (int) config('customers.reassign_after_days');
    }

    public static function startsOn(): CarbonImmutable
    {
        return CarbonImmutable::parse(config('customers.reassign_from'))->startOfDay();
    }

    /**
     * The day the customer opens for reassignment, or null when they ordered again since (nothing to wait for).
     */
    public static function opensOn(?string $lastDelivered, ?string $lastOrdered, ?CarbonImmutable $claimedOn): ?CarbonImmutable
    {
        if ($lastDelivered === null && $claimedOn === null) {
            return null;
        }

        $delivered = $lastDelivered !== null ? CarbonImmutable::parse($lastDelivered)->startOfDay() : null;
        $ordered = $lastOrdered !== null ? CarbonImmutable::parse($lastOrdered)->startOfDay() : null;
        // An order after the last delivery (still on its way), or on or after the claim, is a reorder.
        $reordered = $ordered !== null && (($delivered !== null && $ordered->greaterThan($delivered))
            || ($claimedOn !== null && ! $ordered->lessThan($claimedOn->startOfDay())));

        // Last delivered before the rule started and never claimed: never opens.
        if ($reordered || ($claimedOn === null && $delivered->lessThan(self::startsOn()))) {
            return null;
        }

        $from = collect([$delivered, $claimedOn?->startOfDay()])->filter()->max();

        return $from->addDays(self::afterDays());
    }

    /** How long a worked-out pool is kept: past the next customers:refresh-reassignment run (every 10 minutes). */
    public const CACHE_MINUTES = 25;

    /**
     * Every CRA's customer open for reassignment today, and those opening within SOON_DAYS, newest opening first.
     * Worked out ahead by customers:refresh-reassignment (too big to build inside a page view on the live server).
     *
     * @return list<array{phone_key: string, customer_name: string, phone_number: string, cra_id: int, last_delivered: ?string, opens_on: string, is_open: bool, purchases: int, total_spent: float, high_aov: bool, vip: bool}>
     */
    public function pool(): array
    {
        return Cache::get(self::CACHE_KEY) ?? $this->refresh();
    }

    /**
     * Work the pool out again and keep it for CACHE_MINUTES.
     *
     * @return list<array<string, mixed>>
     */
    public function refresh(): array
    {
        $pool = $this->build(WorkingDate::realToday());
        Cache::put(self::CACHE_KEY, $pool, now()->addMinutes(self::CACHE_MINUTES));

        return $pool;
    }

    /**
     * The open customers another CRA holds, for $cra to claim, narrowed by name / number and High AOV / VIP.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function claimableFor(User $cra, ?string $search = null, string $show = 'all'): Collection
    {
        $term = mb_strtolower(trim((string) $search));
        $digits = preg_replace('/\D+/', '', $term);

        return collect($this->pool())
            ->filter(fn (array $row) => $row['is_open'] && $row['cra_id'] !== $cra->id)
            ->filter(fn (array $row) => $show === 'all' || $row[$show])
            ->filter(fn (array $row) => $term === ''
                || str_contains(mb_strtolower($row['customer_name']), $term)
                || ($digits !== '' && strlen($digits) >= 4 && str_contains($row['phone_number'].' '.$row['phone_key'], ltrim($digits, '0'))))
            ->values();
    }

    /**
     * $cra's own customers who are open now (another CRA can claim them) or open within SOON_DAYS.
     *
     * @return array{open: int, soon: int}
     */
    public function atRiskFor(User $cra): array
    {
        $mine = collect($this->pool())->where('cra_id', $cra->id);

        return ['open' => $mine->where('is_open', true)->count(), 'soon' => $mine->where('is_open', false)->count()];
    }

    /**
     * Make the open customer $cra's: their claim, their High AOV / VIP CRA, and the customer's leads nobody has
     * contacted yet (no date or time of contact, no status) move to $cra on today's list. Website only, nothing in POS.
     *
     * @return int leads moved
     *
     * @throws RuntimeException when the customer isn't open for reassignment or is already theirs
     */
    public function claim(User $cra, string $phoneKey): int
    {
        $primary = (string) (CustomerLink::where('phone_key', $phoneKey)->value('primary_phone_key') ?? $phoneKey);
        $numbers = [$primary, ...CustomerLink::where('primary_phone_key', $primary)->pluck('phone_key')->map(fn ($key) => (string) $key)->all()];

        $moved = DB::transaction(function () use ($cra, $primary, $numbers) {
            $claim = CustomerClaim::where('phone_key', $primary)->lockForUpdate()->first();
            $holder = $this->holderOf($primary);

            if ($holder === $cra->id) {
                throw new RuntimeException('This customer is already yours.');
            }

            $opensOn = self::opensOn(
                LogisticsOrder::whereIn('phone_key', $numbers)->covered()->where('non_crd', false)->max('delivered_date'),
                PancakeOrder::whereIn('phone_key', $numbers)->counted()->max('ordered_on'),
                $claim?->claimed_at,
            );
            if ($holder === null || $opensOn === null || $opensOn->greaterThan(WorkingDate::realToday())) {
                throw new RuntimeException('This customer is no longer up for reassignment.');
            }

            CustomerClaim::updateOrCreate(['phone_key' => $primary], ['user_id' => $cra->id, 'previous_cra_id' => $holder, 'claimed_at' => now()]);
            CustomerAccount::where('phone_key', $primary)->whereNotNull('assigned_cra_id')
                ->update(['assigned_cra_id' => $cra->id, 'assigned_at' => now()]);

            $day = Lead::today();
            $variants = collect($numbers)->flatMap(fn (string $key) => [$key, '0'.$key, '63'.$key, '+63'.$key])->all();
            $leads = Lead::whereIn('phone_number', $variants)
                ->whereNotNull('assigned_to')->where('assigned_to', '!=', $cra->id)
                ->whereNull('status')->whereNull('contact_date')->whereNull('contact_time')->whereNull('processed_at')
                ->withoutPosSales()->lockForUpdate()->get();

            foreach ($leads as $lead) {
                ExclusiveListLead::where('user_id', $lead->assigned_to)->whereDate('list_date', $day)->where('lead_id', $lead->id)->delete();
                $lead->transferTo($cra, $cra, $day);
            }

            return $leads->count();
        });

        // Off the list straight away (theirs now, with 30 new days); the next refresh works the rest out again.
        if (is_array($pool = Cache::get(self::CACHE_KEY))) {
            $left = array_values(array_filter($pool, fn (array $row) => $row['phone_key'] !== $primary));
            Cache::put(self::CACHE_KEY, $left, now()->addMinutes(self::CACHE_MINUTES));
        }

        return $moved;
    }

    /**
     * The customer's CRA now: their claim, else their High AOV / VIP CRA, else who handled the most of their orders.
     */
    private function holderOf(string $primary): ?int
    {
        return $this->owners->claimed([$primary])[$primary]
            ?? $this->highValue->crasFor([$primary])[$primary]
            ?? $this->owners->for([$primary])[$primary]
            ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function build(CarbonImmutable $today): array
    {
        // Nobody can open before the rule's first deadline.
        if ($today->lessThan(self::startsOn()->addDays(self::afterDays() - self::SOON_DAYS))) {
            return [];
        }

        $soonBy = $today->addDays(self::SOON_DAYS);
        $claims = CustomerClaim::get(['phone_key', 'claimed_at'])->keyBy('phone_key');
        $lastOrdered = DB::table('pancake_orders as po')
            ->leftJoin('customer_links as cl', 'cl.phone_key', '=', 'po.phone_key')
            ->whereNotNull('po.phone_key')
            ->where(fn ($q) => $q->whereNull('po.status')->orWhereNotIn('po.status', PancakeOrder::NOT_COUNTED_STATUSES))
            ->groupBy(DB::raw('coalesce(cl.primary_phone_key, po.phone_key)'))
            ->selectRaw('coalesce(cl.primary_phone_key, po.phone_key) as customer_key, max(po.ordered_on) as last_ordered')
            ->pluck('last_ordered', 'customer_key');

        $rows = [];
        foreach (DB::query()->fromSub($this->customers->craCustomers(), 'c')->cursor() as $customer) {
            $key = (string) $customer->phone_key;
            $opensOn = self::opensOn($customer->last_delivered, $lastOrdered[$key] ?? null, $claims[$key]?->claimed_at ?? null);

            if ($opensOn !== null && ! $opensOn->greaterThan($soonBy)) {
                $rows[$key] = [
                    'phone_key' => $key,
                    'customer_name' => $customer->customer_name ?: 'Unknown',
                    'phone_number' => (string) $customer->phone_number,
                    'last_delivered' => $customer->last_delivered,
                    'opens_on' => $opensOn->toDateString(),
                    'is_open' => ! $opensOn->greaterThan($today),
                    'purchases' => (int) $customer->purchases,
                    'total_spent' => (float) $customer->total_spent,
                ];
            }
        }

        if ($rows === []) {
            return [];
        }

        $keys = array_map('strval', array_keys($rows));
        $holders = $this->owners->claimed($keys) + $this->highValue->crasFor($keys) + $this->owners->for($keys);
        $listed = $this->highValue->all();

        return collect($rows)
            ->filter(fn (array $row) => isset($holders[$row['phone_key']]))
            ->map(fn (array $row) => [
                ...$row,
                'cra_id' => (int) $holders[$row['phone_key']],
                'high_aov' => (bool) ($listed[$row['phone_key']]['high_aov'] ?? false),
                'vip' => (bool) ($listed[$row['phone_key']]['vip'] ?? false),
            ])
            ->sortBy([['opens_on', 'desc'], ['total_spent', 'desc']])
            ->values()->all();
    }
}
