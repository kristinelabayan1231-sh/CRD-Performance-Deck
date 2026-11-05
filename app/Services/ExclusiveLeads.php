<?php

namespace App\Services;

use App\Models\BacklogHandout;
use App\Models\ExclusiveListLead;
use App\Models\Lead;
use App\Models\LeadMove;
use App\Models\User;
use App\Support\DutyGoals;
use App\Support\NicheRules;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Exclusive Lead Tracker: CRAs moved out of the Segmentation Tracker (users.exclusive_from) get their own daily list.
 *
 * Days before exclusive_leads.build_from only record the leads the CRA already had. From then on each day fills up to
 * its quota in priority order: what they didn't cater on their last list day, High AOV / VIP customers, customers whose
 * two orders they handled (CustomerOwners), then random leads (the day's and the pool's unassigned leads no other CRA
 * owns). A lead listed for them stays theirs: nothing else moves it (LeadGenerator, BacklogFill).
 *
 * The list's leads are ordinary rows of the leads table, assigned to the CRA and listed on the day (a carried lead moves
 * as the backlog fill's do, LeadMove). That is how every other module reads them: CVR Tracker, Segmentation Productivity,
 * Dashboard, Repeat Purchases and Segmentation Summary by assigned_to and listed_on; PC Report and Daily I.S. through the
 * CVR Tracker's orders. Only the Segmentation Tracker pages leave them out (Lead::scopeInSegmentationTracker).
 */
class ExclusiveLeads
{
    public const LEFTOVER = 'leftover';

    public const HIGH_VALUE = 'high_value';

    public const HANDLED = 'handled';

    public const RANDOM = 'random';

    /** A CRA's own POS sale (PosLeads): shown on the day, never part of the quota. */
    public const POS = 'pos';

    /** Priority order of a day's list. */
    public const PRIORITY = [self::LEFTOVER, self::HIGH_VALUE, self::HANDLED, self::RANDOM];

    public function __construct(private HighValueCustomers $highValue) {}

    /**
     * Bring every exclusive CRA's lists up to date through $day.
     *
     * @return array<string, int> CRA name => leads added to $day
     */
    public function sync(CarbonImmutable $day): array
    {
        return LeadGenerator::cras()
            ->filter(fn (User $cra) => $cra->isExclusiveOn($day))
            ->mapWithKeys(fn (User $cra) => [$cra->displayName() => $this->syncCra($cra, $day)])
            ->all();
    }

    /**
     * Record the month's earlier days as the CRA had them, then build $day. Running it again only adds what is missing.
     *
     * @return int leads added to $day
     */
    public function syncCra(User $cra, CarbonImmutable $day): int
    {
        $day = $day->startOfDay();

        if (! $cra->isExclusiveOn($day)) {
            return 0;
        }

        return Cache::lock("exclusive-leads-{$cra->id}", 300)->get(function () use ($cra, $day) {
            for ($past = $day->startOfMonth()->max($cra->exclusive_from); $past->lessThan($day); $past = $past->addDay()) {
                $this->recordExisting($cra, $past);
            }

            return $this->build($cra, $day);
        }) ?: 0;
    }

    /**
     * @return int leads added to $day
     */
    private function build(User $cra, CarbonImmutable $day): int
    {
        $added = $this->recordExisting($cra, $day);

        if ($day->lessThan(self::buildFrom())) {
            return $added;
        }

        $listed = ExclusiveListLead::where('user_id', $cra->id)->whereDate('list_date', $day)->where('source', '!=', self::POS)->count();
        $room = self::quotaFor($day) - $listed;

        if ($room <= 0) {
            return $added;
        }

        $picks = $this->pick($cra, $day, $room);
        $this->hand($cra, $day, $picks);

        return $added + $picks->count();
    }

    /**
     * Put on the day's list the leads the CRA holds for it, and those moved off it since (LeadMove), which the day keeps.
     *
     * @return int rows added
     */
    private function recordExisting(User $cra, CarbonImmutable $day): int
    {
        $onList = ExclusiveListLead::where('user_id', $cra->id)->whereDate('list_date', $day)->pluck('lead_id');
        $columns = ['id', 'order_id', 'phone_number', 'product_name'];

        $leads = Lead::where('assigned_to', $cra->id)->whereDate('listed_on', $day)->whereNotIn('id', $onList)->get($columns)
            ->concat(Lead::whereIn('id', LeadMove::where('assigned_to', $cra->id)->whereDate('listed_on', $day)->select('lead_id'))->whereNotIn('id', $onList)->get($columns))
            ->unique('id');

        if ($leads->isEmpty()) {
            return 0;
        }

        $classify = $this->classifier($cra, $day, $leads);

        $this->addToList($cra, $day, $leads->mapWithKeys(fn (Lead $lead) => [
            $lead->id => str_starts_with($lead->order_id, Lead::POS_PREFIX) ? self::POS : $classify($lead)['source'],
        ])->all());

        return $leads->count();
    }

    /**
     * The next $room leads for the day, in priority order.
     *
     * @return Collection<int, array{lead: Lead, source: string}>
     */
    private function pick(User $cra, CarbonImmutable $day, int $room): Collection
    {
        $columns = ['id', 'assigned_to', 'phone_number', 'product_name', 'delivered_date', 'listed_on', 'est_out_of_stock_date', 'lead_type', 'customer_tag'];
        $phoneOf = fn (Lead $lead) => LeadGenerator::normalizePhone((string) $lead->phone_number);
        $backlog = app(BacklogFill::class);

        // Already on a list this month: only the leftover step lists a lead again.
        $listedThisMonth = ExclusiveListLead::where('user_id', $cra->id)
            ->whereDate('list_date', '>=', $day->startOfMonth())->whereDate('list_date', '<=', $day)
            ->pluck('lead_id')->flip();
        $onToday = ExclusiveListLead::where('user_id', $cra->id)->whereDate('list_date', $day)->pluck('lead_id');

        $lastListDay = ExclusiveListLead::where('user_id', $cra->id)->whereDate('list_date', '<', $day)->where('source', '!=', self::POS)->max('list_date');
        $leftovers = $lastListDay === null ? collect() : Lead::where('assigned_to', $cra->id)
            ->whereIn('id', ExclusiveListLead::where('user_id', $cra->id)->whereDate('list_date', $lastListDay)->select('lead_id'))
            ->whereNotIn('id', $onToday)
            ->withoutPosSales()->unprocessed()->notConverted()
            ->orderBy('listed_on')->orderBy('id')->get($columns);
        $leftovers = $backlog->withoutReordered($leftovers, $phoneOf);
        $leftoverIds = $leftovers->pluck('id')->flip();

        // Waiting: the unassigned pool and the day's unassigned leads, and untouched backlog of earlier days (only the
        // CRA's own, or their customers' from another CRA).
        $waiting = Lead::withoutPosSales()->whereDate('listed_on', '<=', $day)
            ->where(fn (Builder $q) => $q->where(fn (Builder $q) => $q->whereNull('assigned_to')->unprocessed())
                ->orWhere(fn (Builder $q) => $q->whereDate('listed_on', '<', $day)->whereNotNull('assigned_to')->untouched()))
            ->orderBy('listed_on')->orderBy('id')->get($columns);
        $waiting = $backlog->withoutReordered($waiting, $phoneOf);

        $classify = $this->classifier($cra, $day, $waiting);
        $niches = NicheRules::current();
        $otherExclusive = User::exclusiveOn($day)->where('id', '!=', $cra->id)->pluck('id')->flip();
        $tiers = [self::HIGH_VALUE => [], self::HANDLED => [], self::RANDOM => []];

        foreach ($waiting as $lead) {
            ['owner' => $owner, 'source' => $source] = $classify($lead);
            $holder = $lead->assigned_to;

            $skip = isset($listedThisMonth[$lead->id]) || isset($leftoverIds[$lead->id])
                || ($holder !== null && isset($otherExclusive[$holder]))
                || ($owner !== null && $owner !== $cra->id)
                || ($holder !== null && $holder !== $cra->id && $owner !== $cra->id)
                || ($source === self::RANDOM && ! $niches->allows($cra->id, $lead->product_name));

            if (! $skip) {
                $tiers[$source][] = $lead;
            }
        }

        $inOrder = fn (array $leads) => collect($leads)->sortBy(fn (Lead $lead) => [
            $lead->listed_on->isSameDay($day) ? 0 : 1,
            $lead->lead_type === Lead::TYPE_CRD ? 0 : 1,
            $lead->delivered_date->toDateString(),
            $lead->id,
        ])->values();

        return $leftovers->map(fn (Lead $lead) => ['lead' => $lead, 'source' => self::LEFTOVER])
            ->concat($inOrder($tiers[self::HIGH_VALUE])->map(fn (Lead $lead) => ['lead' => $lead, 'source' => self::HIGH_VALUE]))
            ->concat($inOrder($tiers[self::HANDLED])->map(fn (Lead $lead) => ['lead' => $lead, 'source' => self::HANDLED]))
            ->concat($inOrder($tiers[self::RANDOM])->map(fn (Lead $lead) => ['lead' => $lead, 'source' => self::RANDOM]))
            ->take($room)
            ->values();
    }

    /**
     * Assign the picks to the CRA on $day. A lead from an earlier day moves like the backlog fill's: that day keeps
     * counting it (LeadMove) and the hand-out is logged for Segmentation Productivity.
     *
     * @param  Collection<int, array{lead: Lead, source: string}>  $picks
     */
    private function hand(User $cra, CarbonImmutable $day, Collection $picks): void
    {
        if ($picks->isEmpty()) {
            return;
        }

        $date = $day->toDateString();
        $now = now();
        $leads = $picks->pluck('lead');
        $earlier = $leads->filter(fn (Lead $lead) => $lead->listed_on->lessThan($day));

        DB::transaction(function () use ($cra, $day, $date, $now, $picks, $leads, $earlier) {
            foreach ($earlier->whereNotNull('assigned_to')->chunk(500) as $chunk) {
                LeadMove::insert($chunk->map(fn (Lead $lead) => [
                    'lead_id' => $lead->id, 'assigned_to' => $lead->assigned_to, 'listed_on' => $lead->listed_on->toDateString(),
                    'moved_to' => $date, 'created_at' => $now, 'updated_at' => $now,
                ])->values()->all());
            }

            foreach ($earlier->chunk(500) as $chunk) {
                BacklogHandout::insert($chunk->map(fn (Lead $lead) => [
                    'lead_id' => $lead->id, 'user_id' => $cra->id, 'handed_on' => $date,
                    'source' => $lead->assigned_to !== null ? BacklogHandout::OWN : BacklogHandout::POOL,
                    'created_at' => $now, 'updated_at' => $now,
                ])->values()->all());
            }

            foreach ($leads->pluck('id')->chunk(500) as $ids) {
                Lead::whereIn('id', $ids->all())->update(['assigned_to' => $cra->id, 'assigned_at' => $now, 'listed_on' => $date]);
            }

            $untagged = $picks->where('source', self::HIGH_VALUE)->pluck('lead')->whereNull('customer_tag')->pluck('id');
            if ($untagged->isNotEmpty() && array_key_exists(Lead::HIGH_VALUE_TAG, config('segmentation.customer_tags'))) {
                foreach ($untagged->chunk(500) as $ids) {
                    Lead::whereIn('id', $ids->all())->update(['customer_tag' => Lead::HIGH_VALUE_TAG]);
                }
            }

            $this->addToList($cra, $day, $picks->mapWithKeys(fn (array $pick) => [$pick['lead']->id => $pick['source']])->all());
        });

        // A High AOV / VIP customer with no CRA yet stays with the CRA who got their lead.
        rescue(fn () => $this->highValue->claimForLeads(
            $picks->where('source', self::HIGH_VALUE)
                ->mapWithKeys(fn (array $pick) => [LeadGenerator::normalizePhone((string) $pick['lead']->phone_number) => $cra->id])
                ->filter(fn (int $id, string $phone) => $phone !== '')->all()
        ));
    }

    /**
     * @param  array<int, string>  $sources  lead id => source
     */
    private function addToList(User $cra, CarbonImmutable $day, array $sources): void
    {
        $now = now();
        $rows = collect($sources)->map(fn (string $source, int $leadId) => [
            'user_id' => $cra->id, 'list_date' => $day->toDateString(), 'lead_id' => $leadId, 'source' => $source,
            'created_at' => $now, 'updated_at' => $now,
        ])->values();

        foreach ($rows->chunk(500) as $chunk) {
            ExclusiveListLead::insertOrIgnore($chunk->values()->all());
        }
    }

    /**
     * Each lead's own CRA and which priority it falls under for $cra: their High AOV / VIP customer, or a customer at
     * exclusive_leads.high_aov_min with no other CRA; then a customer whose orders they handled; else random.
     *
     * @param  Collection<int, Lead>  $leads
     * @return Closure(Lead): array{owner: ?int, source: string}
     */
    private function classifier(User $cra, CarbonImmutable $day, Collection $leads): Closure
    {
        $phones = $leads->map(fn (Lead $lead) => LeadGenerator::normalizePhone((string) $lead->phone_number))->filter()->unique()->values()->all();
        $owners = app(LeadGenerator::class)->ownersFor($phones, $this->highValue);
        $flags = rescue(fn () => $this->highValue->flagsForNumbers($phones, (float) config('exclusive_leads.high_aov_min')), []);
        $niches = NicheRules::current();

        return function (Lead $lead) use ($cra, $owners, $flags, $niches) {
            $phone = LeadGenerator::normalizePhone((string) $lead->phone_number);
            $owner = LeadGenerator::ownerFor($owners, $phone, $lead->product_name, $niches);
            $isHighValue = ($owners['high_value'][$phone] ?? null) === $cra->id
                || (isset($flags[$phone]) && ($owner === null || $owner === $cra->id));

            return [
                'owner' => $owner,
                'source' => match (true) {
                    $isHighValue => self::HIGH_VALUE,
                    $owner === $cra->id => self::HANDLED,
                    default => self::RANDOM,
                },
            ];
        };
    }

    public static function quotaFor(CarbonImmutable $day): int
    {
        return (int) (config('exclusive_leads.quota_overrides')[$day->toDateString()] ?? config('exclusive_leads.daily_quota'));
    }

    public static function buildFrom(): CarbonImmutable
    {
        return CarbonImmutable::parse(config('exclusive_leads.build_from'));
    }

    public static function repeatTarget(): int
    {
        return (int) round(config('exclusive_leads.month_target') * config('exclusive_leads.repeat_goal_percent') / 100);
    }

    /**
     * The leads on the CRA's list for $day, for the tracker table.
     */
    public function leadsFor(User $cra, CarbonImmutable $day): Builder
    {
        return Lead::whereIn('id', ExclusiveListLead::where('user_id', $cra->id)->whereDate('list_date', $day)->select('lead_id'));
    }

    /**
     * Why each lead is on the CRA's list for $day.
     *
     * @return Collection<int, string> lead id => source
     */
    public function sourcesFor(User $cra, CarbonImmutable $day): Collection
    {
        return ExclusiveListLead::where('user_id', $cra->id)->whereDate('list_date', $day)->pluck('source', 'lead_id');
    }

    /**
     * Targets and actuals for the month and each of its days. Listed leads leave out POS sales. Repeat purchases follow
     * the tracker's rule (Repeat Purchase = Yes, on the day bought, one per customer: once in the month, once on a day).
     * Confirmed orders, gross and AOV come from Segmentation Productivity. The confirmed orders target is daily: a day
     * off (DutyGoals) or a day before the transfer has none, and the month's is the sum of its days, the daily target
     * × working_days. A lead carried over counts on each day's list; unique_leads counts it once. Worked = processed
     * while on that day's list.
     *
     * @return array{
     *     targets: array{leads: int, repeat: int, repeat_percent: int, confirmed_orders: int, confirmed_orders_per_day: int, working_days: int, high_aov_min: int},
     *     planned: array{leads: int, target: int, short_of_target: int},
     *     actual: array{listed: int, unique_leads: int, repeat: int, confirmed: int, gross: float, aov: ?float, mix: array<string, int>},
     *     days: list<array{date: string, quota: int, is_history: bool, listed: int, worked: int, left: int, pos: int, mix: array<string, int>, repeat: int, repeat_goal: int, confirmed: int, confirmed_goal: int, is_day_off: bool, gross: float, aov: ?float}>
     * }
     */
    public function month(User $cra, CarbonImmutable $month): array
    {
        $start = $month->startOfMonth()->startOfDay();
        $end = $month->endOfMonth()->startOfDay();
        $firstDay = $cra->exclusive_from !== null ? $start->max($cra->exclusive_from) : $start;

        $entries = ExclusiveListLead::where('user_id', $cra->id)
            ->whereDate('list_date', '>=', $start)->whereDate('list_date', '<=', $end)
            ->with('lead:id,listed_on,status,contact_date,processed_at')
            ->get();
        $byDay = $entries->groupBy(fn (ExclusiveListLead $entry) => $entry->list_date->toDateString());

        $bought = Lead::where('assigned_to', $cra->id)->repeatPurchasedBetween($start, $end)
            ->orderBy('repeat_purchase_on')->orderBy('id')
            ->get(['phone_number', 'repeat_purchase_on']);
        $phoneOf = fn (Lead $lead) => LeadGenerator::normalizePhone((string) $lead->phone_number);
        $repeatByDay = $bought->groupBy(fn (Lead $lead) => $lead->repeat_purchase_on->toDateString())
            ->map(fn (Collection $leads) => $leads->unique($phoneOf)->count());

        $sales = app(SegmentationProductivity::class)->days(collect([$cra]), $start, $end)[$cra->id] ?? [];
        $goalPercent = (int) config('exclusive_leads.repeat_goal_percent');
        $confirmedPerDay = (int) config('exclusive_leads.confirmed_orders_per_day');
        $offDays = DutyGoals::offDays(LeadGenerator::cras(), $start, $end);
        $workingDays = 0;
        $mixOf = fn (Collection $rows) => collect(self::PRIORITY)->mapWithKeys(fn (string $source) => [$source => $rows->where('source', $source)->count()])->all();
        $days = [];
        $planned = 0;

        for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            $date = $day->toDateString();
            $rows = collect($byDay[$date] ?? []);
            $quota = $day->lessThan($firstDay) ? 0 : self::quotaFor($day);
            $counted = $rows->where('source', '!=', self::POS);
            $worked = $counted->filter(fn (ExclusiveListLead $entry) => $entry->lead?->isProcessed() && $entry->lead->listed_on->isSameDay($day))->count();
            $planned += $quota;
            $isDayOff = isset($offDays[$date][$cra->id]);
            $isWorkingDay = $quota > 0 && ! $isDayOff;
            $workingDays += (int) $isWorkingDay;

            $days[] = [
                'date' => $date,
                'quota' => $quota,
                'is_history' => $day->lessThan(self::buildFrom()),
                'listed' => $counted->count(),
                'worked' => $worked,
                'left' => $counted->count() - $worked,
                'pos' => $rows->count() - $counted->count(),
                'mix' => $mixOf($rows),
                'repeat' => (int) ($repeatByDay[$date] ?? 0),
                'repeat_goal' => (int) round($quota * $goalPercent / 100),
                'confirmed' => (int) ($sales[$date]['confirmed'] ?? 0),
                'confirmed_goal' => $isWorkingDay ? $confirmedPerDay : 0,
                'is_day_off' => $isDayOff,
                'gross' => (float) ($sales[$date]['gross'] ?? 0),
                'aov' => $sales[$date]['aov'] ?? null,
            ];
        }

        $target = (int) config('exclusive_leads.month_target');
        $counted = $entries->where('source', '!=', self::POS);
        $confirmed = (int) collect($days)->sum('confirmed');
        $gross = (float) collect($days)->sum('gross');

        return [
            'targets' => [
                'leads' => $target,
                'repeat' => self::repeatTarget(),
                'repeat_percent' => $goalPercent,
                'confirmed_orders' => $confirmedPerDay * $workingDays,
                'confirmed_orders_per_day' => $confirmedPerDay,
                'working_days' => $workingDays,
                'high_aov_min' => (int) config('exclusive_leads.high_aov_min'),
            ],
            'planned' => ['leads' => $planned, 'target' => $target, 'short_of_target' => max(0, $target - $planned)],
            'actual' => [
                'listed' => $counted->count(),
                'unique_leads' => $counted->unique('lead_id')->count(),
                'repeat' => $bought->unique($phoneOf)->count(),
                'confirmed' => $confirmed,
                'gross' => $gross,
                'aov' => $confirmed ? $gross / $confirmed : null,
                'mix' => $mixOf($entries),
            ],
            'days' => $days,
        ];
    }
}
