<?php

namespace App\Services;

use App\Models\CustomerAccount;
use App\Models\Lead;
use App\Models\LeadMove;
use App\Models\RestockReminder;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\NicheRules;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A disabled CRA's leads go to the other CRAs from the day they're disabled. Leads they never touched go back to the
 * pool, which the day's hand-out and the backlog fill share out; leads still being worked (no status yet, a carry-over or
 * follow-up status, a callback to make or a reservation) are transferred to the customer's own CRA, else spread evenly,
 * with their notes and history. Closed leads (converted, or Active / Blocked with nothing left to follow) stay theirs.
 * Past lead days keep counting every moved lead for the CRA who had it (LeadMove), so earlier days read the same. Their
 * customers with no other active CRA are shared among the others and stay theirs, and their restock texts not sent yet
 * follow the customer (handOverCustomers); their High AOV / VIP customers are freed so the customer's new CRA gets them
 * (HighValueCustomers).
 */
class DisabledCraHandover
{
    /** Setting holding when a CRA's customers were last handed over: their deactivated_at then, so it runs once per disabling. */
    public const DONE_KEY_PREFIX = 'cra_handover.';

    public function __construct(private LeadGenerator $leads) {}

    /**
     * Hand over every disabled CRA's open leads and High AOV / VIP customers as of lead day $today.
     *
     * @return array{released: int, transferred: int, customers: int}
     */
    public function handOver(CarbonImmutable $today): array
    {
        $disabled = User::whereHas('role', fn ($q) => $q->where('slug', Role::CRA))->where('is_active', false)->pluck('id');
        $none = ['released' => 0, 'transferred' => 0, 'customers' => 0];

        if ($disabled->isEmpty()) {
            return $none;
        }

        // Their customers first, so their leads and reminders follow the customer to the same CRA.
        $customers = $this->handOverCustomers($today);
        CustomerAccount::whereIn('assigned_cra_id', $disabled)
            ->update(['assigned_cra_id' => null, 'assigned_at' => null, 'cra_set_by_hand' => false]);

        $open = fn () => Lead::whereIn('assigned_to', $disabled)->withoutPosSales();
        $untouched = $open()->untouched()->get(['id', 'assigned_to', 'listed_on']);
        $worked = $open()->where(fn (Builder $q) => $q->whereNot(fn (Builder $q) => $q->untouched()))
            ->notConverted()->where(fn (Builder $q) => self::stillOpen($q))->get();

        if ($untouched->isEmpty() && $worked->isEmpty()) {
            return [...$none, 'customers' => $customers];
        }

        $transferred = 0;

        DB::transaction(function () use ($untouched, $worked, $today, &$transferred) {
            $this->release($untouched, $today);
            $transferred = $this->transfer($worked, $today);
        });

        return ['released' => $untouched->count() + $worked->count() - $transferred, 'transferred' => $transferred, 'customers' => $customers];
    }

    /**
     * Once per time a CRA is disabled: their customers with no other active CRA are shared among the Segmentation
     * Tracker's CRAs and stay with them (CustomerOwners::inheritFrom), and their restock texts not sent yet go to each
     * customer's CRA now, back to "to send" for that CRA to send (none when nobody has the customer).
     *
     * @return int customers handed over
     */
    private function handOverCustomers(CarbonImmutable $today): int
    {
        $owners = app(CustomerOwners::class);
        $to = LeadGenerator::segmentationCras($today);
        $handedOver = 0;

        foreach (User::whereHas('role', fn ($q) => $q->where('slug', Role::CRA))->where('is_active', false)->get() as $cra) {
            $doneKey = self::DONE_KEY_PREFIX.$cra->id;
            $disabledAt = (string) $cra->deactivated_at;

            if ($to->isEmpty() || Setting::value($doneKey) === $disabledAt) {
                continue;
            }

            $handedOver += $owners->inheritFrom($cra, $to);

            // Their texts still to go: to the customer's CRA now, else shared out like the tracker's leads (RestockReminders::craPicker()).
            $reminders = RestockReminder::where('user_id', $cra->id)->whereIn('status', [RestockReminder::PENDING, RestockReminder::QUEUED])
                ->whereDate('remind_on', '>=', WorkingDate::realToday())->get(['id', 'phone_key', 'product_id', 'products', 'covers', 'run_out_on']);
            $craFor = app(RestockReminders::class)->craPicker($reminders->pluck('phone_key')->unique()->values()->all(), WorkingDate::realToday());
            $newOwner = $reminders->unique('phone_key')->mapWithKeys(fn (RestockReminder $reminder) => [
                $reminder->phone_key => $craFor($reminder->phone_key, $reminder->coveredItems()[0]['name'] ?? null, null),
            ]);
            foreach ($reminders->groupBy(fn (RestockReminder $reminder) => $newOwner[$reminder->phone_key] ?? 0) as $craId => $group) {
                RestockReminder::whereIn('id', $group->modelKeys())->update(['user_id' => $craId ?: null, 'status' => RestockReminder::PENDING]);
            }

            Setting::put($doneKey, $disabledAt);
        }

        return $handedOver;
    }

    /**
     * Still on a CRA's lists: no status yet or one that is followed up (anything but Active / Blocked), a callback not
     * yet made, or a reservation.
     */
    private static function stillOpen(Builder $query): void
    {
        $query->whereNull('status')
            ->orWhereNotIn('status', config('segmentation.reactivation_skip_statuses'))
            ->orWhereIn('repeat_purchase', Lead::reserveRepeatPurchases())
            ->orWhere(fn (Builder $q) => $q->whereNotNull('callback_date')
                ->where(fn (Builder $q) => $q->whereNull('contact_date')->orWhereColumn('contact_date', '<', 'callback_date')));
    }

    /**
     * Unassign these leads; earlier lead days keep counting them for the CRA who had them.
     *
     * @param  Collection<int, Lead>  $leads
     */
    private function release(Collection $leads, CarbonImmutable $today): void
    {
        foreach ($leads->filter(fn (Lead $lead) => $lead->listed_on->lt($today))->chunk(500) as $chunk) {
            LeadMove::insert($chunk->map(fn (Lead $lead) => [
                'lead_id' => $lead->id, 'assigned_to' => $lead->assigned_to, 'listed_on' => $lead->listed_on->toDateString(),
                'moved_to' => $today->toDateString(), 'created_at' => now(), 'updated_at' => now(),
            ])->values()->all());
        }
        foreach ($leads->pluck('id')->chunk(500) as $ids) {
            Lead::whereIn('id', $ids->all())->update(['assigned_to' => null, 'assigned_at' => null]);
        }
    }

    /**
     * Transfer each worked lead to the customer's own CRA, else to the CRA who has taken the fewest so far whose niche
     * has the product. A lead no CRA can take goes back to the pool.
     *
     * @param  Collection<int, Lead>  $leads
     * @return int leads transferred
     */
    private function transfer(Collection $leads, CarbonImmutable $today): int
    {
        // Oldest account first, as the day's hand-out, so ties split the same way.
        $cras = LeadGenerator::segmentationCras($today)->sortBy('id')->keyBy('id');

        if ($leads->isEmpty() || $cras->isEmpty()) {
            $this->release($leads, $today);

            return 0;
        }

        $phoneOf = fn (Lead $lead) => LeadGenerator::normalizePhone((string) $lead->phone_number);
        $owners = $this->leads->ownersFor($leads->map($phoneOf)->unique()->values()->all());
        $niches = NicheRules::current();
        $load = $cras->map(fn () => 0)->all();
        $leftOver = collect();

        foreach ($leads->sortBy(fn (Lead $lead) => [$lead->listed_on->toDateString(), $lead->id]) as $lead) {
            $owner = LeadGenerator::ownerFor($owners, $phoneOf($lead), $lead->product_name, $niches);
            $open = array_filter($load, fn (int $taken, int $craId) => $niches->allows($craId, $lead->product_name), ARRAY_FILTER_USE_BOTH);
            $craId = $owner !== null && isset($load[$owner]) ? $owner : ($open === [] ? null : array_search(min($open), $open, true));

            if ($craId === null) {
                $leftOver->push($lead);

                continue;
            }

            $lead->transferTo($cras[$craId], null, $today);
            $load[$craId]++;
        }

        $this->release($leftOver, $today);

        return $leads->count() - $leftOver->count();
    }
}
