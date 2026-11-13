<?php

namespace App\Services;

use App\Models\BacklogHandout;
use App\Models\ExclusiveReservedLead;
use App\Models\Lead;
use App\Models\LeadMove;
use App\Models\User;
use App\Support\NicheRules;
use App\Support\OnDuty;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Tops each on-duty CRA's day up to the daily maximum (segmentation.max_leads_per_cra) once their own leads
 * for the day are in, with a mix across months: each pick takes the next out-of-stock month in turn. First the
 * waiting leads of the CRA's own customers (CustomerOwners: most orders handled; High AOV / VIP CRA), wherever they
 * are (the pool, their backlog or another CRA's untouched backlog); then their own untouched backlog of that month,
 * then the unassigned pool (the June–Aug backfill rows with no CRA kept), split evenly. A customer's lead waits for
 * their CRA, never going to another one. A lead whose customer has since ordered the same product again stays where it is. Old months are worked little by little next to the current one. A moved lead is listed on $day
 * (listed_on) in the tracker and every report; its out-of-stock date never changes. One still untouched
 * on a later day is that CRA's backlog again and can move again. Moves off a CRA's day are kept (LeadMove)
 * so that day's total never changes.
 *
 * Each CRA is filled once a day: the first fill sets the day's list (up to 100; fewer when the backlog runs out),
 * and reports divide by the leads actually listed. Processed leads still count, and later runs never top a CRA up again.
 */
class BacklogFill
{
    private const LOCK_SECONDS = 300;

    /** Pool leads read per out-of-stock month at the least (waiting()). */
    private const POOL_PER_MONTH_MIN = 200;

    /** A CRA's untouched backlog read per out-of-stock month for each of their open slots (waiting()). */
    private const BACKLOG_PER_SLOT = 2;

    /** A CRA's untouched backlog read per out-of-stock month at the least (waiting()). */
    private const BACKLOG_PER_MONTH_MIN = 100;

    /**
     * Fill the CRAs logged in today (Segmentation Tracker), together so the pool splits evenly.
     *
     * @return array<int, int> CRA id => leads moved to $day
     */
    public function fillOnDuty(CarbonImmutable $day): array
    {
        $onDuty = OnDuty::today()->pluck('user_id')->all();

        return $this->fill($day, LeadGenerator::segmentationCras($day)->whereIn('id', $onDuty)->values());
    }

    /**
     * @param  Collection<int, User>  $cras
     * @return array<int, int> CRA id => leads moved to $day
     */
    public function fill(CarbonImmutable $day, Collection $cras): array
    {
        $day = $day->startOfDay();

        // One fill at a time, so a log-in and the hourly sync can't both fill a CRA. A fill that dies keeps the lock until it
        // expires; the next hourly sync then tries again. A run that finds it taken leaves the CRAs to that sync too.
        return Cache::lock('segmentation.backlog_fill.'.$day->toDateString(), self::LOCK_SECONDS)
            ->get(fn () => $this->fillOnce($day, $cras)) ?: [];
    }

    /**
     * Fill the CRAs not filled yet. They're marked filled only once their leads have moved, all of them or none, so a
     * failure never leaves a CRA short for the day.
     *
     * @param  Collection<int, User>  $cras
     * @return array<int, int> CRA id => leads moved to $day
     */
    private function fillOnce(CarbonImmutable $day, Collection $cras): array
    {
        $cras = $cras->reject(fn (User $cra) => $cra->isExclusiveOn($day) || Cache::has(self::filledKey($day, $cra->id)))->values();
        $markFilled = fn () => $cras->each(fn (User $cra) => Cache::put(self::filledKey($day, $cra->id), true, now()->addDays(3)));
        $max = (int) config('segmentation.max_leads_per_cra');
        $listed = Lead::whereDate('listed_on', $day)->whereIn('assigned_to', $cras->pluck('id'))->withoutPosSales()
            ->selectRaw('assigned_to, count(*) as total')->groupBy('assigned_to')->pluck('total', 'assigned_to');
        $room = $cras->mapWithKeys(fn (User $cra) => [$cra->id => max(0, $max - (int) ($listed[$cra->id] ?? 0))])->filter()->all();
        $moved = array_fill_keys(array_keys($room), 0);

        if ($room === []) {
            $markFilled();

            return $moved;
        }

        // Every lead waiting before $day: untouched backlog (any CRA's) and the unassigned pool, the longest-waiting first
        // (an untouched lead moved yesterday goes to the back). Only leads nobody touched (no status, date, note or any
        // other field) move: worked ones stay on their day.
        $waiting = $this->waiting($day, $room);
        $phoneOf = fn (Lead $lead) => LeadGenerator::normalizePhone((string) $lead->phone_number);
        // An exclusive CRA's leads stay theirs.
        $exclusive = User::exclusiveOn($day)->pluck('id')->flip();
        $waiting = $waiting->reject(fn (Lead $lead) => $lead->assigned_to !== null && isset($exclusive[$lead->assigned_to]))->values();
        $waiting = $this->withoutReordered($waiting, $phoneOf);
        $owners = app(LeadGenerator::class)->ownersFor($waiting->map($phoneOf)->unique()->values()->all());
        $niches = NicheRules::current();
        $active = LeadGenerator::segmentationCras($day)->pluck('id')->flip();

        // Three lists, each by out-of-stock month: a filled CRA's own customers (whoever has the lead now: their CRA
        // takes it back), then their backlog of customers no CRA owns, then the unowned pool. A lead whose CRA isn't
        // being filled now stays for them. Backlog of a product outside its CRA's niche goes to the pool.
        $owned = [];
        $own = [];
        $pool = [];
        foreach ($waiting as $lead) {
            $owner = LeadGenerator::ownerFor($owners, $phoneOf($lead), $lead->product_name, $niches);
            $month = $lead->est_out_of_stock_date->format('Y-m');

            // An exclusive CRA's customer waits for their list (ExclusiveLeads).
            if ($owner !== null && isset($exclusive[$owner])) {
                continue;
            }

            if ($owner !== null && isset($active[$owner])) {
                if (isset($room[$owner])) {
                    $owned[$owner][$month][] = $lead;
                }
            } elseif ($lead->assigned_to === null || ! $niches->allows($lead->assigned_to, $lead->product_name)) {
                $pool[$month][] = $lead;
            } elseif (isset($room[$lead->assigned_to])) {
                $own[$lead->assigned_to][$month][] = $lead;
            }
        }

        $months = $waiting->map(fn (Lead $lead) => $lead->est_out_of_stock_date->format('Y-m'))->unique()->sort()->values()->all();
        $turn = array_fill_keys(array_keys($room), 0);
        $taken = [];
        $none = [];

        // One lead at a time per CRA, each pick from the next month in turn: their own customers' leads while any are
        // left, then their backlog of that month before pool ones (of their niche's products), so every month moves a
        // little each day and the pool splits evenly.
        while ($room !== []) {
            foreach (array_keys($room) as $craId) {
                $fits = $niches->active() ? fn (Lead $lead) => $niches->allows($craId, $lead->product_name) : null;
                $lead = $this->nextLead($months, $turn[$craId], $owned[$craId], $none)
                    ?? $this->nextLead($months, $turn[$craId], $own[$craId], $pool, $fits);

                if ($lead === null || --$room[$craId] === 0) {
                    unset($room[$craId]);
                }

                if ($lead !== null) {
                    $taken[$craId][] = $lead;
                }
            }
        }

        DB::transaction(function () use ($taken, $day, &$moved) {
            foreach ($taken as $craId => $leads) {
                // The day each own lead leaves keeps counting it (LeadMove).
                foreach (array_chunk(array_filter($leads, fn (Lead $lead) => $lead->assigned_to !== null), 500) as $chunk) {
                    // The CRA who had it: their own, or another CRA's when it goes back to its customer's CRA.
                    LeadMove::insert(array_map(fn (Lead $lead) => [
                        'lead_id' => $lead->id, 'assigned_to' => $lead->assigned_to, 'listed_on' => $lead->listed_on->toDateString(),
                        'moved_to' => $day->toDateString(), 'created_at' => now(), 'updated_at' => now(),
                    ], $chunk));
                }

                // Who got each one, for Segmentation Productivity's Backlog progress.
                foreach (array_chunk($leads, 500) as $chunk) {
                    BacklogHandout::insert(array_map(fn (Lead $lead) => [
                        'lead_id' => $lead->id, 'user_id' => $craId, 'handed_on' => $day->toDateString(),
                        'source' => $lead->assigned_to !== null ? BacklogHandout::OWN : BacklogHandout::POOL,
                        'created_at' => now(), 'updated_at' => now(),
                    ], $chunk));
                }

                $this->move(array_map(fn (Lead $lead) => $lead->id, $leads), $craId, $day);
                $moved[$craId] = count($leads);
            }
        });

        $markFilled();

        return $moved;
    }

    /**
     * Every lead waiting before $day, the longest-waiting first, read a month at a time and only as many as a day can take:
     * of each out-of-stock month, each CRA's oldest untouched backlog (BACKLOG_PER_SLOT for each of their open $room
     * slots, at least BACKLOG_PER_MONTH_MIN; another CRA's backlog, which only moves to the customer's own CRA, the
     * minimum) and the pool's oldest leads (one for each of the day's slots, at least POOL_PER_MONTH_MIN). A day takes at
     * most its slots from a month, so the picks come out as from everything, and memory stays small however much is
     * waiting (tens of thousands of leads did run out of it). An exclusive CRA's backlog waits for their list.
     *
     * @param  array<int, int>  $room  CRA id => open slots
     * @return Collection<int, Lead>
     */
    private function waiting(CarbonImmutable $day, array $room): Collection
    {
        $columns = ['id', 'assigned_to', 'phone_number', 'product_name', 'delivered_date', 'listed_on', 'est_out_of_stock_date'];
        $waitingBefore = fn () => Lead::whereDate('listed_on', '<', $day)->withoutPosSales()->whereNotIn('id', ExclusiveReservedLead::select('lead_id'));
        $untouched = fn () => $waitingBefore()->whereNotNull('assigned_to')->untouched();
        $pool = fn () => $waitingBefore()->whereNull('assigned_to')->unprocessed();

        $exclusive = User::exclusiveOn($day)->pluck('id')->all();
        $backlogCras = LeadGenerator::cras()->pluck('id')->diff($exclusive)->values();
        $range = Lead::whereDate('listed_on', '<', $day)->withoutPosSales()
            ->where(fn ($q) => $q->where(fn ($q) => $q->whereNull('assigned_to')->unprocessed())->orWhere(fn ($q) => $q->whereIn('assigned_to', $backlogCras)->untouched()))
            ->toBase()->selectRaw('min(est_out_of_stock_date) as first_day, max(est_out_of_stock_date) as last_day')->first();

        if ($range?->first_day === null) {
            return collect();
        }

        $poolPerMonth = max(self::POOL_PER_MONTH_MIN, array_sum($room));
        $leads = collect();
        $last = CarbonImmutable::parse($range->last_day);

        for ($month = CarbonImmutable::parse($range->first_day)->startOfMonth(); ! $month->greaterThan($last); $month = $month->addMonth()) {
            $inMonth = fn ($query) => $query->whereBetween('est_out_of_stock_date', [$month->toDateString(), $month->endOfMonth()->toDateString()])
                ->orderBy('listed_on')->orderBy('id');

            foreach ($backlogCras as $craId) {
                $perMonth = max(self::BACKLOG_PER_MONTH_MIN, self::BACKLOG_PER_SLOT * ($room[$craId] ?? 0));
                $leads = $leads->concat($inMonth($untouched()->where('assigned_to', $craId))->limit($perMonth)->get($columns));
            }

            $leads = $leads->concat($inMonth($pool())->limit($poolPerMonth)->get($columns));
        }

        return $leads->sortBy(fn (Lead $lead) => [$lead->listed_on->toDateString(), $lead->id])->values();
    }

    /**
     * $waiting without the leads whose customer has a later order of the same product in the tracker (any CRA's lead,
     * a POS sale included): they bought again, so the old lead is never handed out.
     *
     * @param  Collection<int, Lead>  $waiting
     * @param  callable(Lead): string  $phoneOf
     * @return Collection<int, Lead>
     */
    public function withoutReordered(Collection $waiting, callable $phoneOf): Collection
    {
        // Lead numbers are stored as entered: 9…, 09…, 639… or +639….
        $formats = $waiting->map($phoneOf)->filter()->unique()->flatMap(fn (string $key) => [$key, '0'.$key, '63'.$key, '+63'.$key])->all();
        $lastDelivered = [];

        // Plain rows, a chunk at a time: these customers have tens of thousands of leads, too many to hold as models.
        foreach (array_chunk($formats, 1000) as $chunk) {
            foreach (Lead::whereIn('phone_number', $chunk)->toBase()->get(['phone_number', 'product_name', 'delivered_date']) as $row) {
                $key = LeadGenerator::normalizePhone((string) $row->phone_number).'|'.$row->product_name;
                $delivered = substr((string) $row->delivered_date, 0, 10);

                if ($delivered > ($lastDelivered[$key] ?? '')) {
                    $lastDelivered[$key] = $delivered;
                }
            }
        }

        return $waiting->reject(fn (Lead $lead) => ($lastDelivered[$phoneOf($lead).'|'.$lead->product_name] ?? '') > $lead->delivered_date->toDateString())->values();
    }

    /**
     * The next lead from the first month, starting at $turn, that still has one; $turn moves past it. With $fits, only
     * a pool lead it accepts (the CRA's niche) is taken.
     *
     * @param  list<string>  $months
     * @param  array<string, list<Lead>>|null  $own
     * @param  array<string, list<Lead>>  $pool
     * @param  (callable(Lead): bool)|null  $fits
     */
    private function nextLead(array $months, int &$turn, ?array &$own, array &$pool, ?callable $fits = null): ?Lead
    {
        $count = count($months);

        for ($step = 0; $step < $count; $step++) {
            $month = $months[($turn + $step) % $count];
            $lead = ($own[$month] ?? []) !== [] ? array_shift($own[$month]) : $this->takeFromPool($pool, $month, $fits);

            if ($lead !== null) {
                $turn = ($turn + $step + 1) % $count;

                return $lead;
            }
        }

        return null;
    }

    /**
     * The first pool lead of $month (that $fits accepts, when given), taken out of the pool.
     *
     * @param  array<string, list<Lead>>  $pool
     * @param  (callable(Lead): bool)|null  $fits
     */
    private function takeFromPool(array &$pool, string $month, ?callable $fits): ?Lead
    {
        foreach ($pool[$month] ?? [] as $index => $lead) {
            if ($fits === null || $fits($lead)) {
                array_splice($pool[$month], $index, 1);

                return $lead;
            }
        }

        return null;
    }

    private static function filledKey(CarbonImmutable $day, int $craId): string
    {
        return 'segmentation.backlog_fill.'.$day->toDateString().'.'.$craId;
    }

    /**
     * @param  list<int>  $ids
     */
    private function move(array $ids, int $craId, CarbonImmutable $day): void
    {
        foreach (array_chunk($ids, 500) as $chunk) {
            Lead::whereIn('id', $chunk)->update(['assigned_to' => $craId, 'assigned_at' => now(), 'listed_on' => $day->toDateString()]);
        }
    }
}
