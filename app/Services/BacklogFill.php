<?php

namespace App\Services;

use App\Models\BacklogHandout;
use App\Models\Lead;
use App\Models\LeadMove;
use App\Models\User;
use App\Support\NicheRules;
use App\Support\OnDuty;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Tops each on-duty CRA's day up to the daily maximum (segmentation.max_leads_per_cra) once their own leads
 * for the day are in, with a mix across months: each pick takes the next out-of-stock month in turn. First the
 * waiting leads of the CRA's own customers (CustomerOwners: two orders handled; High AOV / VIP CRA), wherever they
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
    /**
     * Fill the CRAs logged in today (Segmentation Tracker), together so the pool splits evenly.
     *
     * @return array<int, int> CRA id => leads moved to $day
     */
    public function fillOnDuty(CarbonImmutable $day): array
    {
        $onDuty = OnDuty::today()->pluck('user_id')->all();

        return $this->fill($day, LeadGenerator::cras()->whereIn('id', $onDuty)->values());
    }

    /**
     * @param  Collection<int, User>  $cras
     * @return array<int, int> CRA id => leads moved to $day
     */
    public function fill(CarbonImmutable $day, Collection $cras): array
    {
        $day = $day->startOfDay();
        $cras = $cras->reject(fn (User $cra) => Cache::has(self::filledKey($day, $cra->id)))->values();
        $cras->each(fn (User $cra) => Cache::put(self::filledKey($day, $cra->id), true, now()->addDays(3)));
        $max = (int) config('segmentation.max_leads_per_cra');
        $listed = Lead::whereDate('listed_on', $day)->whereIn('assigned_to', $cras->pluck('id'))->withoutPosSales()
            ->selectRaw('assigned_to, count(*) as total')->groupBy('assigned_to')->pluck('total', 'assigned_to');
        $room = $cras->mapWithKeys(fn (User $cra) => [$cra->id => max(0, $max - (int) ($listed[$cra->id] ?? 0))])->filter()->all();
        $moved = array_fill_keys(array_keys($room), 0);

        if ($room === []) {
            return $moved;
        }

        // Every lead waiting before $day: untouched backlog (any CRA's) and the unassigned pool, the longest-waiting first
        // (an untouched lead moved yesterday goes to the back). Only leads nobody touched (no status, date, note or any
        // other field) move: worked ones stay on their day.
        $waiting = Lead::whereDate('listed_on', '<', $day)->withoutPosSales()
            ->where(fn ($q) => $q->where(fn ($q) => $q->whereNull('assigned_to')->unprocessed())->orWhere(fn ($q) => $q->untouched()))
            ->orderBy('listed_on')->orderBy('id')->get(['id', 'assigned_to', 'phone_number', 'product_name', 'delivered_date', 'listed_on', 'est_out_of_stock_date']);
        $phoneOf = fn (Lead $lead) => LeadGenerator::normalizePhone((string) $lead->phone_number);
        $waiting = $this->withoutReordered($waiting, $phoneOf);
        $owners = app(LeadGenerator::class)->ownersFor($waiting->map($phoneOf)->unique()->values()->all());
        $niches = NicheRules::current();
        $active = LeadGenerator::cras()->pluck('id')->flip();

        // Three lists, each by out-of-stock month: a filled CRA's own customers (whoever has the lead now: their CRA
        // takes it back), then their backlog of customers no CRA owns, then the unowned pool. A lead whose CRA isn't
        // being filled now stays for them. Backlog of a product outside its CRA's niche goes to the pool.
        $owned = [];
        $own = [];
        $pool = [];
        foreach ($waiting as $lead) {
            $owner = LeadGenerator::ownerFor($owners, $phoneOf($lead), $lead->product_name, $niches);
            $month = $lead->est_out_of_stock_date->format('Y-m');

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

        return $moved;
    }

    /**
     * $waiting without the leads whose customer has a later order of the same product in the tracker (any CRA's lead,
     * a POS sale included): they bought again, so the old lead is never handed out.
     *
     * @param  Collection<int, Lead>  $waiting
     * @param  callable(Lead): string  $phoneOf
     * @return Collection<int, Lead>
     */
    private function withoutReordered(Collection $waiting, callable $phoneOf): Collection
    {
        // Lead numbers are stored as entered: 9…, 09…, 639… or +639….
        $formats = $waiting->map($phoneOf)->filter()->unique()->flatMap(fn (string $key) => [$key, '0'.$key, '63'.$key, '+63'.$key]);
        $lastDelivered = $formats->chunk(1000)
            ->flatMap(fn (Collection $chunk) => Lead::whereIn('phone_number', $chunk->values()->all())->get(['phone_number', 'product_name', 'delivered_date']))
            ->groupBy(fn (Lead $lead) => $phoneOf($lead).'|'.$lead->product_name)
            ->map(fn (Collection $leads) => $leads->max('delivered_date'));

        return $waiting->reject(fn (Lead $lead) => $lastDelivered->get($phoneOf($lead).'|'.$lead->product_name)?->greaterThan($lead->delivered_date) ?? false)->values();
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
