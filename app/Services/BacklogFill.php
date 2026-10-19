<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadMove;
use App\Models\User;
use App\Support\OnDuty;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Tops each on-duty CRA's day up to the daily maximum (segmentation.max_leads_per_cra) once their own leads
 * for the day are in, with a mix across months: each pick takes the next out-of-stock month in turn, the CRA's own
 * untouched backlog of that month first, then the unassigned pool (the June–Aug backfill rows with no CRA kept),
 * so old months are worked little by little next to the current one. A moved lead is listed on $day
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

        // Each CRA's own untouched backlog and the unassigned pool, grouped by out-of-stock month; within a month,
        // the longest-waiting first (an untouched lead moved yesterday goes to the back of its month).
        // Only leads the CRA never touched (no status, date, note or any other field): worked ones stay on their day.
        $own = Lead::whereIn('assigned_to', array_keys($room))->whereDate('listed_on', '<', $day)
            ->untouched()->withoutPosSales()
            ->orderBy('listed_on')->orderBy('id')->get(['id', 'assigned_to', 'listed_on', 'est_out_of_stock_date'])
            ->groupBy(['assigned_to', fn (Lead $lead) => $lead->est_out_of_stock_date->format('Y-m')])
            ->map(fn (Collection $months) => $months->map(fn (Collection $leads) => $leads->all())->all())->all();
        $pool = Lead::whereNull('assigned_to')->whereDate('listed_on', '<', $day)->withoutPosSales()
            ->orderBy('listed_on')->orderBy('id')->get(['id', 'listed_on', 'est_out_of_stock_date'])
            ->groupBy(fn (Lead $lead) => $lead->est_out_of_stock_date->format('Y-m'))
            ->map(fn (Collection $leads) => $leads->all())->all();
        $months = collect(array_keys($pool))->merge(collect($own)->flatMap(fn (array $byMonth) => array_keys($byMonth)))
            ->unique()->sort()->values()->all();
        $turn = array_fill_keys(array_keys($room), 0);
        $taken = [];

        // One lead at a time per CRA, each pick from the next month in turn (own leads of that month before pool ones),
        // so every month moves a little each day and the pool splits evenly.
        while ($room !== []) {
            foreach (array_keys($room) as $craId) {
                $lead = $this->nextLead($months, $turn[$craId], $own[$craId], $pool);

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
                LeadMove::insert(array_map(fn (Lead $lead) => [
                    'lead_id' => $lead->id, 'assigned_to' => $craId, 'listed_on' => $lead->listed_on->toDateString(),
                    'moved_to' => $day->toDateString(), 'created_at' => now(), 'updated_at' => now(),
                ], $chunk));
            }

            $this->move(array_map(fn (Lead $lead) => $lead->id, $leads), $craId, $day);
            $moved[$craId] = count($leads);
        }

        return $moved;
    }

    /**
     * The next lead from the first month, starting at $turn, that still has one; $turn moves past it.
     *
     * @param  list<string>  $months
     * @param  array<string, list<Lead>>|null  $own
     * @param  array<string, list<Lead>>  $pool
     */
    private function nextLead(array $months, int &$turn, ?array &$own, array &$pool): ?Lead
    {
        $count = count($months);

        for ($step = 0; $step < $count; $step++) {
            $month = $months[($turn + $step) % $count];
            $lead = ($own[$month] ?? []) !== [] ? array_shift($own[$month]) : (($pool[$month] ?? []) !== [] ? array_shift($pool[$month]) : null);

            if ($lead !== null) {
                $turn = ($turn + $step + 1) % $count;

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
