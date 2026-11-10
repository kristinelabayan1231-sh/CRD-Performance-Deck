<?php

namespace App\Services;

use App\Models\BacklogHandout;
use App\Models\ExclusiveListLead;
use App\Models\ExclusiveReservedLead;
use App\Models\Lead;
use App\Models\LeadMove;
use App\Models\PancakeOrder;
use App\Models\User;
use App\Support\DutyGoals;
use App\Support\ExclusiveLimits;
use App\Support\NicheRules;
use App\Support\WorkingDate;
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
 * its quota in priority order: what they didn't cater on their last list day, High AOV / VIP customers, customers who are
 * theirs by orders handled (CustomerOwners), then random leads (the day's and the pool's unassigned FSD leads no other
 * CRA owns). It never takes a lead another CRA holds. A lead listed for them stays theirs: nothing else moves it
 * (LeadGenerator, BacklogFill). Only this class does, each day before the leftovers carry over: an untouched leftover
 * goes to the CRA who owns the customer, and a CRD lead with no reason to be theirs goes back to the pool (releaseLeftovers).
 *
 * From exclusive_leads.flat_month_from the month is a flat target of different leads: each day hands out up to its quota of
 * new leads until the target is reached, leftovers carry on top without using it, and the leads that qualify for the rest of
 * the month are held for the CRA (ExclusiveReservedLead) so no other CRA gets them. It may end short when too few qualify.
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

    /** recheck()'s groups: another CRA owns the customer, and the lead is untouched or already contacted. */
    public const OWNED_UNTOUCHED = 'owned_untouched';

    public const OWNED_CONTACTED = 'owned_contacted';

    /** recheck()'s groups: CRD leads with no reason to be theirs, on their current list or held from before it. */
    public const CRD_ON_LIST = 'crd_on_list';

    public const CRD_OLDER = 'crd_older';

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

        $isFlat = ! $day->lessThan(self::flatMonthFrom());
        $room = max(0, $isFlat ? $this->newLeadsRoom($cra, $day) : self::quotaFor($day) - $this->listedOn($cra, $day)->count());
        $onToday = $this->listedOn($cra, $day)->pluck('lead_id');
        $hasWork = $room > 0
            || ($isFlat && ($this->leftovers($cra, $day)->whereNotIn('id', $onToday)->exists()
                || Cache::add("exclusive-reserved-{$cra->id}-{$day->toDateString()}", true, now()->addDay())));

        if (! $hasWork) {
            return $added;
        }

        $this->releaseLeftovers($cra, $day);

        ['picks' => $picks, 'upcoming' => $upcoming] = $this->pick($cra, $day, $room, $isFlat);
        $this->hand($cra, $day, $picks);

        if ($isFlat) {
            $this->reserve($cra, $day, $upcoming);
        }

        return $added + $picks->count();
    }

    /**
     * The day's list, POS sales left out.
     *
     * @return Builder<ExclusiveListLead>
     */
    private function listedOn(User $cra, CarbonImmutable $day): Builder
    {
        return ExclusiveListLead::where('user_id', $cra->id)->whereDate('list_date', $day)->where('source', '!=', self::POS);
    }

    /**
     * How many new leads the day can still hand out: its quota less the new leads it has, within what's left of the month's target.
     */
    private function newLeadsRoom(User $cra, CarbonImmutable $day): int
    {
        $newToday = $this->listedOn($cra, $day)->where('source', '!=', self::LEFTOVER)->count();

        return min(self::quotaFor($day) - $newToday, $this->monthRemaining($cra, $day));
    }

    /**
     * What's left of the month's target of different leads, by the leads still theirs on the month's lists.
     */
    private function monthRemaining(User $cra, CarbonImmutable $day): int
    {
        [$from, $to] = $this->leadDaysOfMonth($day);
        $different = ExclusiveListLead::where('user_id', $cra->id)->where('source', '!=', self::POS)
            ->whereDate('list_date', '>=', $from)->whereDate('list_date', '<=', $to)
            ->whereIn('lead_id', Lead::where('assigned_to', $cra->id)->select('id'))
            ->distinct()->count('lead_id');

        return ExclusiveLimits::on($day->addDays(WorkingDate::lagDays()))['month_target'] - $different;
    }

    /**
     * How far the leads in the tracker fall short of the month's target of different leads, once $day's list and what can
     * be held for the days ahead are counted.
     */
    public function monthShortfall(User $cra, CarbonImmutable $day): int
    {
        ['picks' => $picks, 'upcoming' => $upcoming] = $this->pick($cra, $day, max(0, $this->newLeadsRoom($cra, $day)), true);

        return max(0, $this->monthRemaining($cra, $day) - $picks->where('source', '!=', self::LEFTOVER)->count() - $upcoming->count());
    }

    /**
     * The first and last lead days of the real month $leadDay is worked in.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function leadDaysOfMonth(CarbonImmutable $leadDay): array
    {
        $lag = WorkingDate::lagDays();
        $real = $leadDay->addDays($lag);

        return [$real->startOfMonth()->startOfDay()->subDays($lag), $real->endOfMonth()->startOfDay()->subDays($lag)];
    }

    /**
     * Hold these leads for the days ahead, in place of what was held. Older months' holds and leads no longer qualifying go free.
     *
     * @param  Collection<int, array{lead: Lead, source: string}>  $upcoming
     */
    private function reserve(User $cra, CarbonImmutable $day, Collection $upcoming): void
    {
        $month = $day->addDays(WorkingDate::lagDays())->startOfMonth()->toDateString();
        $now = now();
        $held = $upcoming->pluck('lead.id')->flip();

        DB::transaction(function () use ($cra, $month, $now, $held, $upcoming) {
            $gone = ExclusiveReservedLead::where('user_id', $cra->id)->get(['id', 'lead_id'])
                ->reject(fn (ExclusiveReservedLead $reserved) => isset($held[$reserved->lead_id]))->pluck('id');
            foreach ($gone->chunk(500) as $ids) {
                ExclusiveReservedLead::whereIn('id', $ids->all())->delete();
            }

            foreach ($upcoming->chunk(500) as $chunk) {
                ExclusiveReservedLead::insertOrIgnore($chunk->map(fn (array $pick) => [
                    'user_id' => $cra->id, 'month' => $month, 'lead_id' => $pick['lead']->id, 'source' => $pick['source'],
                    'created_at' => $now, 'updated_at' => $now,
                ])->values()->all());
            }

            ExclusiveReservedLead::where('user_id', $cra->id)->update(['month' => $month]);
        });
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

        // Another CRA's High AOV / VIP customers stay theirs: even the CRA's own sale to one doesn't go on the list.
        $highValueCras = rescue(fn () => $this->highValue->crasFor(
            $leads->map(fn (Lead $lead) => LeadGenerator::normalizePhone((string) $lead->phone_number))->filter()->unique()->values()->all(),
        ), []);
        $leads = $leads->reject(fn (Lead $lead) => ($highValueCras[LeadGenerator::normalizePhone((string) $lead->phone_number)] ?? $cra->id) !== $cra->id);

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
     * The day's leads in priority order: $room in all, or on a flat month every leftover plus $room new ones, and the
     * qualifying leads after those, up to what's left of the month's target, for the days ahead.
     *
     * @return array{picks: Collection<int, array{lead: Lead, source: string}>, upcoming: Collection<int, array{lead: Lead, source: string}>}
     */
    private function pick(User $cra, CarbonImmutable $day, int $room, bool $isFlat): array
    {
        $columns = ['id', 'assigned_to', 'phone_number', 'product_name', 'delivered_date', 'listed_on', 'est_out_of_stock_date', 'lead_type', 'customer_tag'];
        $phoneOf = fn (Lead $lead) => LeadGenerator::normalizePhone((string) $lead->phone_number);
        $backlog = app(BacklogFill::class);

        // Already on a list this month: only the leftover step lists a lead again.
        [$monthStart] = $this->leadDaysOfMonth($day);
        $listedThisMonth = ExclusiveListLead::where('user_id', $cra->id)
            ->whereDate('list_date', '>=', $monthStart)->whereDate('list_date', '<=', $day)
            ->pluck('lead_id')->flip();
        $onToday = ExclusiveListLead::where('user_id', $cra->id)->whereDate('list_date', $day)->pluck('lead_id');

        $leftovers = $backlog->withoutReordered($this->leftovers($cra, $day)->whereNotIn('id', $onToday)->orderBy('listed_on')->orderBy('id')->get($columns), $phoneOf);
        $leftoverIds = $leftovers->pluck('id')->flip();

        // Waiting: the unassigned pool and the day's unassigned leads not held for another exclusive CRA, and the CRA's own
        // untouched backlog of earlier days. A lead another CRA holds is never taken.
        $waiting = Lead::withoutPosSales()->whereDate('listed_on', '<=', $day)
            ->whereNotIn('id', ExclusiveReservedLead::where('user_id', '!=', $cra->id)->select('lead_id'))
            ->where(fn (Builder $q) => $q->where(fn (Builder $q) => $q->whereNull('assigned_to')->unprocessed())
                ->orWhere(fn (Builder $q) => $q->whereDate('listed_on', '<', $day)->where('assigned_to', $cra->id)->untouched()))
            ->orderBy('listed_on')->orderBy('id')->get($columns);
        $waiting = $backlog->withoutReordered($waiting, $phoneOf);

        $classify = $this->classifier($cra, $day, $waiting);
        $niches = NicheRules::current();
        $tiers = [self::HIGH_VALUE => [], self::HANDLED => [], self::RANDOM => []];

        foreach ($waiting as $lead) {
            ['owner' => $owner, 'source' => $source] = $classify($lead);

            $skip = isset($listedThisMonth[$lead->id]) || isset($leftoverIds[$lead->id])
                || ($owner !== null && $owner !== $cra->id)
                || ($source === self::RANDOM && ($lead->lead_type !== Lead::TYPE_FSD || ! $niches->allows($cra->id, $lead->product_name)));

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

        $carried = $leftovers->map(fn (Lead $lead) => ['lead' => $lead, 'source' => self::LEFTOVER]);
        $new = collect([self::HIGH_VALUE, self::HANDLED, self::RANDOM])
            ->flatMap(fn (string $source) => $inOrder($tiers[$source])->map(fn (Lead $lead) => ['lead' => $lead, 'source' => $source]))
            ->values();

        if (! $isFlat) {
            return ['picks' => $carried->concat($new)->take($room)->values(), 'upcoming' => collect()];
        }

        return [
            'picks' => $carried->concat($new->take($room))->values(),
            'upcoming' => $new->slice($room)->take(max(0, $this->monthRemaining($cra, $day) - min($room, $new->count())))->values(),
        ];
    }

    /**
     * What they didn't cater on their last list day.
     *
     * @return Builder<Lead>
     */
    private function leftovers(User $cra, CarbonImmutable $day): Builder
    {
        $lastListDay = ExclusiveListLead::where('user_id', $cra->id)->whereDate('list_date', '<', $day)->where('source', '!=', self::POS)->max('list_date');

        return Lead::where('assigned_to', $cra->id)
            ->whereIn('id', ExclusiveListLead::where('user_id', $cra->id)->whereDate('list_date', $lastListDay ?? '1000-01-01')->select('lead_id'))
            ->withoutPosSales()->unprocessed()->notConverted();
    }

    /**
     * Before leftovers carry over: an untouched one that isn't the CRA's by the rules leaves them (notHers). A lead someone
     * wrote on or tagged stays with them, as the backlog fill leaves it too.
     */
    private function releaseLeftovers(User $cra, CarbonImmutable $day): void
    {
        $leads = $this->leftovers($cra, $day)->untouched()->get(['id', 'assigned_to', 'phone_number', 'product_name', 'listed_on', 'est_out_of_stock_date', 'lead_type']);
        $classify = $this->classifier($cra, $day, $leads);

        DB::transaction(fn () => $this->release($cra, $day, $leads->map(fn (Lead $lead) => $this->notHers($classify($lead), $lead))->filter()));
    }

    /**
     * Where a lead goes if the rules don't make it the CRA's, null if they do: High AOV / VIP and handled customers stay,
     * as does an FSD lead no other CRA owns. A customer another CRA owns goes to that CRA; any other CRD lead to the pool.
     *
     * @param  array{owner: ?int, source: string}  $classified
     * @return array{lead: Lead, to: ?int}|null
     */
    private function notHers(array $classified, Lead $lead): ?array
    {
        if ($classified['source'] !== self::RANDOM || ($classified['owner'] === null && $lead->lead_type === Lead::TYPE_FSD)) {
            return null;
        }

        return ['lead' => $lead, 'to' => $classified['owner']];
    }

    /**
     * Take these leads off the CRA from $day on, and off $day's list: to their new CRA, or back to the pool when that CRA
     * is gone or there is none. Their old days keep counting them (LeadMove).
     *
     * @param  Collection<int, array{lead: Lead, to: ?int}>  $moves
     */
    private function release(User $cra, CarbonImmutable $day, Collection $moves): void
    {
        $active = LeadGenerator::cras()->keyBy('id');

        foreach ($moves as ['lead' => $lead, 'to' => $to]) {
            if ($to !== null && $active->has($to)) {
                $lead->transferTo($active[$to], null, $day);

                continue;
            }

            if ($lead->listed_on->lessThan($day)) {
                LeadMove::create(['lead_id' => $lead->id, 'assigned_to' => $cra->id, 'listed_on' => $lead->listed_on->toDateString(), 'moved_to' => $day->toDateString()]);
            }
            $lead->forceFill(['assigned_to' => null, 'assigned_at' => null])->save();
        }

        ExclusiveListLead::where('user_id', $cra->id)->whereDate('list_date', $day)
            ->whereIn('lead_id', $moves->map(fn (array $move) => $move['lead']->id)->all())->delete();
    }

    /**
     * Every open lead the CRA holds, on any day, checked against the rules: for putting right what was listed before them.
     * Leads with a purchase or on Reserve, on a status that doesn't carry over, and POS sales are left out.
     *
     * @return array<string, Collection<int, array{lead: Lead, to: ?int}>>
     */
    public function recheck(User $cra, CarbonImmutable $day): array
    {
        $leads = Lead::where('assigned_to', $cra->id)->withoutPosSales()->notConverted()
            ->where(fn (Builder $q) => $q->whereNull('status')->orWhereIn('status', config('segmentation.carry_over_statuses')))
            ->where(fn (Builder $q) => $q->whereNull('repeat_purchase')->orWhereNotIn('repeat_purchase', Lead::reserveRepeatPurchases()))
            ->orderBy('listed_on')->orderBy('id')->get();
        $untouched = Lead::where('assigned_to', $cra->id)->untouched()->pluck('id')->flip();
        $onList = ExclusiveListLead::where('user_id', $cra->id)->whereDate('list_date', $day)->pluck('lead_id')->flip();
        $classify = $this->classifier($cra, $day, $leads);
        $groups = [self::OWNED_UNTOUCHED => collect(), self::OWNED_CONTACTED => collect(), self::CRD_ON_LIST => collect(), self::CRD_OLDER => collect()];

        foreach ($leads as $lead) {
            $move = $this->notHers($classify($lead), $lead);
            $isUntouched = isset($untouched[$lead->id]);
            $group = match (true) {
                $move === null => null,
                $move['to'] !== null => $isUntouched ? self::OWNED_UNTOUCHED : self::OWNED_CONTACTED,
                ! $isUntouched => null,
                isset($onList[$lead->id]) => self::CRD_ON_LIST,
                default => self::CRD_OLDER,
            };

            if ($group !== null) {
                $groups[$group]->push($move);
            }
        }

        return $groups;
    }

    /**
     * Move what recheck() found, but for the older CRD leads, which were never on a list.
     *
     * @param  array<string, Collection<int, array{lead: Lead, to: ?int}>>  $found
     * @return int leads moved
     */
    public function applyRecheck(User $cra, CarbonImmutable $day, array $found): int
    {
        $moves = collect([self::OWNED_UNTOUCHED, self::OWNED_CONTACTED, self::CRD_ON_LIST])->flatMap(fn (string $group) => $found[$group]);

        DB::transaction(fn () => $this->release($cra, $day, $moves));

        return $moves->count();
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

    /**
     * A lead day's list size: limits start on real dates, so it goes by the real day paired with $leadDay.
     */
    public static function quotaFor(CarbonImmutable $leadDay): int
    {
        return (int) (config('exclusive_leads.quota_overrides')[$leadDay->toDateString()]
            ?? ExclusiveLimits::on($leadDay->addDays(WorkingDate::lagDays()))['daily_leads']);
    }

    public static function flatMonthFrom(): CarbonImmutable
    {
        return CarbonImmutable::parse(config('exclusive_leads.flat_month_from'));
    }

    public static function buildFrom(): CarbonImmutable
    {
        return CarbonImmutable::parse(config('exclusive_leads.build_from'));
    }

    public static function repeatTarget(int $monthTarget): int
    {
        return (int) round($monthTarget * config('exclusive_leads.repeat_goal_percent') / 100);
    }

    /**
     * The leads on the CRA's list for $day, for the tracker table.
     */
    public function leadsFor(User $cra, CarbonImmutable $day): Builder
    {
        return Lead::whereIn('id', ExclusiveListLead::where('user_id', $cra->id)->whereDate('list_date', $day)->select('lead_id'));
    }

    /**
     * Every lead still theirs on the CRA's lists for real days $from–$to so far, each once. POS sales stay out, as in the month's goals.
     */
    public function leadsBetween(User $cra, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return Lead::where('assigned_to', $cra->id)->whereIn('id', $this->entriesBetween($cra, $from, $to)->select('lead_id'));
    }

    /**
     * The real month's whole list: every lead still theirs on its lists so far, and those held for its days ahead.
     */
    public function monthLeads(User $cra, CarbonImmutable $month): Builder
    {
        return Lead::where(fn (Builder $q) => $q
            ->where(fn (Builder $q) => $q->where('assigned_to', $cra->id)
                ->whereIn('id', $this->entriesBetween($cra, $month->startOfMonth(), $month->endOfMonth()->startOfDay())->select('lead_id')))
            ->orWhereIn('id', ExclusiveReservedLead::where('user_id', $cra->id)->whereDate('month', $month->startOfMonth())->select('lead_id')));
    }

    /**
     * Every lead still theirs on the CRA's lists from Jan 1 of $today's year to $today, and those held for this month's days ahead.
     */
    public function yearLeads(User $cra, CarbonImmutable $today): Builder
    {
        return Lead::where(fn (Builder $q) => $q
            ->where(fn (Builder $q) => $q->where('assigned_to', $cra->id)
                ->whereIn('id', $this->entriesBetween($cra, $today->startOfYear(), $today)->select('lead_id')))
            ->orWhereIn('id', ExclusiveReservedLead::where('user_id', $cra->id)->whereDate('month', $today->startOfMonth())->select('lead_id')));
    }

    /**
     * Every active CRA on the Exclusive Lead Tracker, by name.
     *
     * @return Collection<int, User>
     */
    public function cras(): Collection
    {
        return User::query()->whereNotNull('exclusive_from')->where('is_active', true)
            ->get()->sortBy(fn (User $cra) => mb_strtolower($cra->displayName()))->values();
    }

    /**
     * Why each lead held for the real month's days ahead qualifies.
     *
     * @return array<int, string> lead id => source
     */
    public function upcomingSources(User $cra, CarbonImmutable $month): array
    {
        return ExclusiveReservedLead::where('user_id', $cra->id)->whereDate('month', $month->startOfMonth())->pluck('source', 'lead_id')->all();
    }

    /**
     * Why each lead of real days $from–$to was last listed, and the real day it was last on a list and how many days it was on one.
     *
     * @return array{sources: array<int, string>, days: array<int, array{last: string, count: int}>}
     */
    public function listDetailsBetween(User $cra, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $lag = WorkingDate::lagDays();
        $details = ['sources' => [], 'days' => []];

        $stillTheirs = Lead::where('assigned_to', $cra->id)->select('id');

        foreach ($this->entriesBetween($cra, $from, $to)->whereIn('lead_id', $stillTheirs)->orderBy('list_date')->get(['lead_id', 'list_date', 'source']) as $entry) {
            $details['sources'][$entry->lead_id] = $entry->source;
            $details['days'][$entry->lead_id] = [
                'last' => $entry->list_date->addDays($lag)->toDateString(),
                'count' => ($details['days'][$entry->lead_id]['count'] ?? 0) + 1,
            ];
        }

        return $details;
    }

    /**
     * @return Builder<ExclusiveListLead>
     */
    /**
     * The customers on the CRA's lists for real days $from–$to who bought again in those days (Repeat Purchase = Yes),
     * each once: Repeat for a CRD Lead, Retained for an FSD Lead. Only leads on the lists count, POS sales on them too, so
     * a sale to a customer kept off the list (another CRA's High AOV/VIP, an old lead) stays out.
     *
     * @return array{repeat: int, retained: int}
     */
    public function repeatsBetween(User $cra, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $bought = Lead::where('assigned_to', $cra->id)
            ->whereIn('id', $this->entriesBetween($cra, $from, $to, withPos: true)->select('lead_id'))
            ->repeatPurchasedBetween($from, $to)
            ->orderBy('repeat_purchase_on')->orderBy('id')
            ->get(['id', 'phone_number', 'lead_type'])
            ->unique(fn (Lead $lead) => LeadGenerator::normalizePhone((string) $lead->phone_number) ?: 'lead-'.$lead->id);

        return [
            'repeat' => $bought->where('lead_type', Lead::TYPE_CRD)->count(),
            'retained' => $bought->where('lead_type', Lead::TYPE_FSD)->count(),
        ];
    }

    /**
     * The CRA's confirmed orders on real days $from–$to (as Segmentation Productivity: tagged CRD orders counted for them,
     * canceled and deleted left out) from customers on their lists for those days, POS sales included, with their gross.
     *
     * @return array{orders: int, gross: float}
     */
    public function ordersBetween(User $cra, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $listed = Lead::where('assigned_to', $cra->id)
            ->whereIn('id', $this->entriesBetween($cra, $from, $to, withPos: true)->select('lead_id'))
            ->pluck('phone_number')
            ->map(fn (?string $phone) => LeadGenerator::normalizePhone((string) $phone))
            ->filter()->unique()->values();
        $accounts = ConversionBreakdown::accounts(collect([$cra]));
        if ($listed->isEmpty() || $accounts->isEmpty()) {
            return ['orders' => 0, 'gross' => 0.0];
        }

        $orders = $listed->chunk(1000)->flatMap(fn (Collection $phones) => ConversionBreakdown::taggedOrders($accounts, $from, $to)
            ->where(fn (Builder $q) => $q->whereNull('pancake_orders.status')->orWhereNotIn('pancake_orders.status', PancakeOrder::NOT_COUNTED_STATUSES))
            ->whereIn('pancake_orders.phone_key', $phones->all())
            ->get())
            ->filter(fn (PancakeOrder $order) => ConversionBreakdown::craFor($order, $accounts) === $cra->id)
            ->unique('id');

        return ['orders' => $orders->count(), 'gross' => (float) $orders->sum(fn (PancakeOrder $order) => $order->sales())];
    }

    private function entriesBetween(User $cra, CarbonImmutable $from, CarbonImmutable $to, bool $withPos = false): Builder
    {
        $lag = WorkingDate::lagDays();

        return ExclusiveListLead::where('user_id', $cra->id)->when(! $withPos, fn (Builder $q) => $q->where('source', '!=', self::POS))
            ->whereDate('list_date', '>=', $from->subDays($lag))
            ->whereDate('list_date', '<=', $to->subDays($lag));
    }

    /**
     * The CRA's leads matching $term (name, contact # or order #) on any day: their lists' and any lead they hold.
     */
    public function search(User $cra, string $term): Builder
    {
        return Lead::query()
            ->where(fn (Builder $q) => $q->where('assigned_to', $cra->id)
                ->orWhereIn('id', ExclusiveListLead::where('user_id', $cra->id)->select('lead_id')))
            ->search($term);
    }

    /**
     * Why each lead was last on the CRA's list, for search results across days.
     *
     * @return Collection<int, string> lead id => source
     */
    public function latestSources(User $cra): Collection
    {
        return ExclusiveListLead::where('user_id', $cra->id)->orderBy('list_date')->pluck('source', 'lead_id');
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
     * The customers (last 10 digits of the number) with an order carrying the exclusive POS tag, for the Tag column.
     *
     * @return array<string, int> phone key => any value, to look up with isset()
     */
    public function taggedCustomers(): array
    {
        return PancakeOrder::whereJsonContains('tags', config('exclusive_leads.order_tag.id'))
            ->whereNotNull('phone_key')
            ->distinct()
            ->pluck('phone_key')
            ->flip()
            ->all();
    }

    /**
     * Targets and actuals for the month and each of its days. Listed leads leave out POS sales. Repeat purchases follow
     * the tracker's rule (Repeat Purchase = Yes, on the day bought, one per customer: once in the month, once on a day).
     * Confirmed orders, gross and AOV come from Segmentation Productivity. The confirmed orders target is daily: a day
     * off (DutyGoals) or a day before the transfer has none, and the month's is the sum of its days, the daily target
     * × working_days. Targets follow the limits set in Settings (ExclusiveLimits). A lead carried over counts on each day's list; unique_leads counts it once. Worked = processed
     * while on that day's list. $month and the days are real dates, when the CRA did the work; each day's list and quota
     * are its paired lead day's (WorkingDate), so November's days hold October's leads while the team is a month behind.
     *
     * @return array{
     *     targets: array{leads: int, repeat: int, repeat_percent: int, confirmed_orders: int, confirmed_orders_per_day: int, working_days: int, high_aov_min: int},
     *     limits: array{daily_leads: int, orders_rate_changed: bool},
     *     planned: array{leads: int, target: int, short_of_target: int},
     *     actual: array{listed: int, unique_leads: int, repeat: int, confirmed: int, gross: float, aov: ?float, mix: array<string, int>},
     *     days: list<array{date: string, lead_date: string, quota: int, is_history: bool, listed: int, worked: int, left: int, pos: int, mix: array<string, int>, repeat: int, repeat_goal: int, confirmed: int, confirmed_goal: int, is_day_off: bool, gross: float, aov: ?float}>
     * }
     */
    public function month(User $cra, CarbonImmutable $month): array
    {
        $start = $month->startOfMonth()->startOfDay();
        $end = $month->endOfMonth()->startOfDay();
        $lag = WorkingDate::lagDays();
        $firstDay = $cra->exclusive_from !== null ? $start->max($cra->exclusive_from->addDays($lag)) : $start;

        $entries = ExclusiveListLead::where('user_id', $cra->id)
            ->whereDate('list_date', '>=', $start->subDays($lag))->whereDate('list_date', '<=', $end->subDays($lag))
            ->with('lead:id,assigned_to,listed_on,status,contact_date,processed_at')
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
        // The month goes by the limits its last day ends on; each day's orders target by that day's.
        $monthLimits = ExclusiveLimits::on($end);
        $orderRates = [];
        $offDays = DutyGoals::offDays(LeadGenerator::cras(), $start, $end);
        $workingDays = 0;
        $mixOf = fn (Collection $rows) => collect(self::PRIORITY)->mapWithKeys(fn (string $source) => [$source => $rows->where('source', $source)->count()])->all();
        $days = [];
        $target = $monthLimits['month_target'];
        $seen = [];
        $held = 0;
        $leadToday = Lead::today();

        for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            $date = $day->toDateString();
            $leadDay = $day->subDays($lag);
            $rows = collect($byDay[$leadDay->toDateString()] ?? []);
            $quota = $day->lessThan($firstDay) ? 0 : self::quotaFor($leadDay);
            $counted = $rows->where('source', '!=', self::POS);
            $new = $counted->where('source', '!=', self::LEFTOVER);
            foreach ($counted as $entry) {
                if ($entry->lead?->assigned_to === $cra->id) {
                    $seen[$entry->lead_id] = true;
                }
            }
            // Past days are what was listed; from today, up to the quota until the month's target is reached.
            if (! $leadDay->lessThan($leadToday) && ! $day->lessThan($firstDay)) {
                $quota = max(0, min($quota, $target - count($seen) - $held));
                $held += $quota;
            }
            $worked = $counted->filter(fn (ExclusiveListLead $entry) => $entry->lead?->isProcessed() && $entry->lead->listed_on->isSameDay($leadDay))->count();
            $isBeforeTransfer = $day->lessThan($firstDay);
            $isDayOff = isset($offDays[$date][$cra->id]);
            $isWorkingDay = ! $isBeforeTransfer && ! $isDayOff;
            $workingDays += (int) $isWorkingDay;
            $confirmedGoal = $isWorkingDay ? ExclusiveLimits::on($day)['orders_per_day'] : 0;
            if ($isWorkingDay) {
                $orderRates[] = $confirmedGoal;
            }

            $days[] = [
                'date' => $date,
                'lead_date' => $leadDay->toDateString(),
                'quota' => $quota,
                'is_history' => $leadDay->lessThan(self::buildFrom()),
                'is_before_transfer' => $isBeforeTransfer,
                'listed' => $counted->count(),
                'new' => $new->count(),
                'worked' => $worked,
                'left' => $counted->count() - $worked,
                'pos' => $rows->count() - $counted->count(),
                'mix' => $mixOf($rows),
                'repeat' => (int) ($repeatByDay[$date] ?? 0),
                'repeat_goal' => (int) round($quota * $goalPercent / 100),
                'confirmed' => (int) ($sales[$date]['confirmed'] ?? 0),
                'confirmed_goal' => $confirmedGoal,
                'is_day_off' => $isDayOff,
                'gross' => (float) ($sales[$date]['gross'] ?? 0),
                'aov' => $sales[$date]['aov'] ?? null,
            ];
        }

        $counted = $entries->where('source', '!=', self::POS);
        $planned = count($seen) + $held;
        $confirmed = (int) collect($days)->sum('confirmed');
        $gross = (float) collect($days)->sum('gross');

        return [
            'targets' => [
                'leads' => $target,
                'repeat' => self::repeatTarget($target),
                'repeat_percent' => $goalPercent,
                'confirmed_orders' => array_sum($orderRates),
                'confirmed_orders_per_day' => $monthLimits['orders_per_day'],
                'working_days' => $workingDays,
                'high_aov_min' => (int) config('exclusive_leads.high_aov_min'),
            ],
            'limits' => ['daily_leads' => $monthLimits['daily_leads'], 'orders_rate_changed' => count(array_unique($orderRates)) > 1],
            'planned' => ['leads' => $planned, 'target' => $target, 'short_of_target' => max(0, $target - $planned)],
            'actual' => [
                'listed' => $counted->count(),
                'unique_leads' => count($seen),
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
