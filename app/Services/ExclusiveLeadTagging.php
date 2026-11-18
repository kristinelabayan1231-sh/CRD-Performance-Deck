<?php

namespace App\Services;

use App\Models\ExclusiveLeadTag;
use App\Models\ExclusiveOldTag;
use App\Models\ExclusiveRemovedLead;
use App\Models\ExclusiveReservedLead;
use App\Models\Lead;
use App\Models\PancakeOrder;
use App\Models\User;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The POS order tag CRD - ANNA (EXCLUSIVE LEADS) (exclusive_leads.order_tag) and the exclusive CRA's leads go together:
 *
 * - Every lead of her month (on her lists or held for her days ahead) gets the tag on its order (tagNext).
 * - A supervisor who tags an open lead's order in POS gives it to her (newlyTagged → ExclusiveLeads::addTagged).
 * - A supervisor who takes the tag off in POS, or with the Tag column's Remove (remove), takes the lead off her for good,
 *   and another takes its place (ExclusiveLeads::takeOff). A lead she already worked stays hers.
 * - A lead that leaves her another way (the rules, a supervisor's reassignment) has the tag taken off (untagLeft).
 *
 * The leads whose tag it looks after are in exclusive_lead_tags. Changes made in POS reach the website with the Pancake
 * tag sync (pancake:sync --tags / --new, every few minutes). The tag belongs to the first CRA made exclusive (Anna).
 */
class ExclusiveLeadTagging
{
    /**
     * Minutes after the tag was seen on an order before its absence counts as taken off: a sync that read the order before
     * the tag went on can save it without it, until the next sync puts it right.
     */
    private const SETTLE_MINUTES = 30;

    /** Hours before a Pancake write that failed is tried again. */
    private const RETRY_HOURS = 24;

    private const CHUNK = 1000;

    public function __construct(private ExclusiveLeads $exclusive, private PancakeClient $pancake) {}

    public static function tagId(): int
    {
        return (int) config('exclusive_leads.order_tag.id');
    }

    /**
     * The CRA the tag gives leads to: the first CRA made exclusive who still is on $day.
     */
    public function owner(CarbonImmutable $day): ?User
    {
        return $this->exclusive->cras()
            ->filter(fn (User $cra) => $cra->isExclusiveOn($day))
            ->sortBy(fn (User $cra) => [$cra->exclusive_from->toDateString(), $cra->id])
            ->first();
    }

    public static function orderNumber(Lead $lead): string
    {
        return Str::after((string) $lead->order_id, Lead::POS_PREFIX);
    }

    /**
     * Leads due by $day whose order a supervisor tagged in POS, not hers yet and not held for her, that nobody has
     * contacted yet (no status, date or time of contact) and that aren't sold. Leads whose tag the website looks after are
     * left out: their tag is coming off (untagLeft). So are orders tagged from before (ExclusiveOldTag): CRAs pasted her
     * numbers into Pancake, so those tags aren't a supervisor's. So are another CRA's High AOV / VIP customers and customers another
     * CRA claimed: those stay with that CRA, tag or not.
     *
     * @return Collection<int, Lead>
     */
    public function newlyTagged(User $cra, CarbonImmutable $day): Collection
    {
        $leads = PancakeOrder::whereJsonContains('tags', self::tagId())->distinct()->pluck('pancake_order_id')
            ->chunk(self::CHUNK)
            ->flatMap(fn (Collection $numbers) => Lead::whereIn('order_id', $numbers->values()->all())
                ->where(fn (Builder $q) => $q->whereNull('assigned_to')->orWhere('assigned_to', '!=', $cra->id))
                ->withoutPosSales()->unprocessed()->whereNull('contact_time')->notConverted()
                ->whereDate('listed_on', '<=', $day)
                ->whereNotIn('id', ExclusiveReservedLead::select('lead_id'))
                ->whereNotIn('id', ExclusiveLeadTag::select('lead_id'))
                ->whereNotIn('order_id', ExclusiveOldTag::select('pancake_order_id'))
                ->get())
            ->values();

        $phones = $leads->map(fn (Lead $lead) => LeadGenerator::normalizePhone((string) $lead->phone_number))->filter()->unique()->values()->all();
        $highValue = rescue(fn () => app(HighValueCustomers::class)->crasFor($phones), []);
        $claimed = app(CustomerOwners::class)->claimed($phones);

        return $leads->reject(function (Lead $lead) use ($cra, $highValue, $claimed) {
            $phone = LeadGenerator::normalizePhone((string) $lead->phone_number);

            return ($highValue[$phone] ?? $cra->id) !== $cra->id || ($claimed[$phone] ?? $cra->id) !== $cra->id;
        })->values();
    }

    /**
     * Leads of hers whose tag may have been taken off in POS: the website saw the tag on their order, and the synced order
     * no longer has it. applyTakenOff() checks each in Pancake before anything moves.
     *
     * @return Collection<int, Lead>
     */
    public function takenOffInPos(User $cra): Collection
    {
        $found = collect();

        ExclusiveLeadTag::where('user_id', $cra->id)->whereNotNull('tagged_at')->where('tagged_at', '<', now()->subMinutes(self::SETTLE_MINUTES))
            ->orderBy('id')->chunkById(self::CHUNK, function (Collection $rows) use ($cra, &$found) {
                $leads = $this->hers($cra, Lead::whereIn('id', $rows->pluck('lead_id')->all()))->get();
                $orders = PancakeOrder::whereIn('pancake_order_id', $leads->map(fn (Lead $lead) => self::orderNumber($lead))->unique()->values()->all())
                    ->get(['pancake_order_id', 'tags'])->keyBy('pancake_order_id');

                $found = $found->concat($leads->filter(function (Lead $lead) use ($orders) {
                    $order = $orders[self::orderNumber($lead)] ?? null;

                    return $order !== null && ! in_array(self::tagId(), array_map('intval', $order->tags ?? []), true);
                }));
            });

        return $found->values();
    }

    /**
     * Take the tag-less leads off her (open ones), or leave a lead she already worked with her and stop looking after its tag.
     *
     * @param  Collection<int, Lead>  $leads  from takenOffInPos()
     * @return array{removed: int, kept: int}
     */
    public function applyTakenOff(User $cra, CarbonImmutable $day, Collection $leads): array
    {
        if ($leads->isEmpty()) {
            return ['removed' => 0, 'kept' => 0];
        }

        // Only what Pancake itself shows: a sync that read an order before the tag went on may have saved it without.
        $live = collect($this->pancake->ordersByNumber($leads->map(fn (Lead $lead) => self::orderNumber($lead))->unique()->values()->all()))
            ->keyBy(fn (array $order) => (string) ($order['display_id'] ?? ''))
            ->map(fn (array $order) => collect($order['tags'] ?? [])->map(fn ($tag) => (int) (is_array($tag) ? ($tag['id'] ?? 0) : $tag))->filter()->values()->all());
        foreach ($live as $number => $tags) {
            if (in_array(self::tagId(), $tags, true)) {
                $this->saveTags((string) $number, $tags);
            }
        }
        $leads = $leads->filter(fn (Lead $lead) => $live->has(self::orderNumber($lead)) && ! in_array(self::tagId(), $live[self::orderNumber($lead)], true))->values();
        $this->forgetOldTags($leads->map(fn (Lead $lead) => self::orderNumber($lead))->all());

        $open = Lead::whereIn('id', $leads->pluck('id')->all())->unprocessed()->notConverted()->pluck('id')->flip();
        [$remove, $keep] = $leads->partition(fn (Lead $lead) => isset($open[$lead->id]));

        ExclusiveLeadTag::whereIn('lead_id', $keep->pluck('id')->all())->update(['tagged_at' => null, 'failed_at' => null]);
        $this->exclusive->takeOff($cra, $day, $remove->values(), null, ExclusiveRemovedLead::VIA_POS);

        foreach ($remove as $lead) {
            Log::info('Exclusive lead taken off: tag removed in POS', ['lead_id' => $lead->id, 'order' => self::orderNumber($lead), 'user_id' => $cra->id]);
        }

        return ['removed' => $remove->count(), 'kept' => $keep->count()];
    }

    /**
     * The leads of her month whose order still needs the tag. A lead whose tag failed is
     * tried again after RETRY_HOURS.
     *
     * @return Builder<Lead>
     */
    public function untaggedLeads(User $cra): Builder
    {
        return $this->exclusive->monthLeads($cra, WorkingDate::realToday())->withoutPosSales()
            ->whereNotIn('id', ExclusiveLeadTag::where(fn (Builder $q) => $q->whereNull('failed_at')
                ->orWhere('failed_at', '>', now()->subHours(self::RETRY_HOURS)))->select('lead_id'));
    }

    /**
     * Put the tag on the orders of her month's leads: those whose synced order has it already are only noted; then up to
     * $limit orders are tagged in Pancake, one at a time.
     *
     * @return array{already: int, tagged: int, failed: int}
     */
    public function tagNext(User $cra, int $limit): array
    {
        $done = ['already' => 0, 'tagged' => 0, 'failed' => 0];

        $this->untaggedLeads($cra)->chunkById(self::CHUNK, function (Collection $leads) use ($cra, &$done) {
            $tagged = PancakeOrder::whereIn('pancake_order_id', $leads->map(fn (Lead $lead) => self::orderNumber($lead))->unique()->values()->all())
                ->whereJsonContains('tags', self::tagId())->pluck('pancake_order_id')->flip();

            foreach ($leads->filter(fn (Lead $lead) => isset($tagged[self::orderNumber($lead)])) as $lead) {
                $this->note($cra, $lead, ['tagged_at' => now(), 'failed_at' => null, 'last_error' => null]);
                $done['already']++;
            }
        });

        foreach ($this->untaggedLeads($cra)->orderBy('listed_on')->orderBy('id')->limit($limit)->get() as $lead) {
            $orderNumber = self::orderNumber($lead);

            try {
                $tags = $this->pancake->addOrderTag($orderNumber, self::tagId());
            } catch (RuntimeException $e) {
                Log::warning('Exclusive tag not added', ['lead_id' => $lead->id, 'order' => $orderNumber, 'message' => $e->getMessage()]);
                $this->note($cra, $lead, ['tagged_at' => null, 'failed_at' => now(), 'last_error' => Str::limit($e->getMessage(), 490)]);
                $done['failed']++;

                continue;
            }

            $this->saveTags($orderNumber, $tags['after']);
            $this->note($cra, $lead, ['tagged_at' => now(), 'failed_at' => null, 'last_error' => null]);
            $done['tagged']++;
        }

        return $done;
    }

    /**
     * Leads whose tag the website put on or saw, that are no longer hers (the rules moved them, a supervisor reassigned
     * them): the tag comes off their order, up to $limit Pancake writes; an order without it is only crossed off.
     *
     * @return array{untagged: int, cleared: int, failed: int}
     */
    public function untagLeft(User $cra, int $limit): array
    {
        $done = ['untagged' => 0, 'cleared' => 0, 'failed' => 0];
        $rows = ExclusiveLeadTag::where('user_id', $cra->id)
            ->whereNotIn('lead_id', $this->hers($cra, Lead::query())->select('id'))
            ->where(fn (Builder $q) => $q->whereNull('failed_at')->orWhere('failed_at', '<', now()->subHours(self::RETRY_HOURS)))
            ->with('lead:id,order_id')->orderBy('id')->get();

        foreach ($rows as $row) {
            $orderNumber = $row->lead ? self::orderNumber($row->lead) : null;
            $hasTag = $orderNumber !== null && $row->tagged_at !== null
                && PancakeOrder::where('pancake_order_id', $orderNumber)->whereJsonContains('tags', self::tagId())->exists();

            if (! $hasTag) {
                $row->delete();
                $done['cleared']++;

                continue;
            }
            if ($limit <= $done['untagged'] + $done['failed'] || $this->hers($cra, Lead::whereKey($row->lead_id))->exists()) {
                continue;
            }

            try {
                $tags = $this->pancake->removeOrderTag($orderNumber, self::tagId());
            } catch (RuntimeException $e) {
                Log::warning('Exclusive tag not removed', ['lead_id' => $row->lead_id, 'order' => $orderNumber, 'message' => $e->getMessage()]);
                $row->update(['failed_at' => now(), 'last_error' => Str::limit($e->getMessage(), 490)]);
                $done['failed']++;

                continue;
            }

            Log::info('Exclusive tag removed: lead left the exclusive CRA', ['lead_id' => $row->lead_id, 'order' => $orderNumber, 'tags_before' => $tags['before'], 'tags_after' => $tags['after']]);
            $this->saveTags($orderNumber, $tags['after']);
            $this->forgetOldTags([$orderNumber]);
            $row->delete();
            $done['untagged']++;
        }

        return $done;
    }

    /**
     * The Tag column's Remove: the tag comes off the lead's order in Pancake, and the lead off her for good; another lead
     * takes its place.
     *
     * @throws DomainException when the lead isn't hers or she already worked it
     * @throws RuntimeException when Pancake can't take the tag off (nothing else changes)
     */
    public function remove(Lead $lead, User $cra, User $by): void
    {
        if (! $this->hers($cra, Lead::whereKey($lead->id))->exists()) {
            throw new DomainException("{$lead->customer_name}'s lead isn't on {$cra->displayName()}'s list.");
        }
        if (! Lead::whereKey($lead->id)->unprocessed()->notConverted()->exists()) {
            throw new DomainException("{$lead->customer_name}'s lead was already worked, so it stays with {$cra->displayName()}.");
        }

        $orderNumber = self::orderNumber($lead);
        $tags = $this->pancake->removeOrderTag($orderNumber, self::tagId());
        Log::info('Exclusive lead taken off on the website', ['lead_id' => $lead->id, 'order' => $orderNumber, 'user_id' => $by->id, 'tags_before' => $tags['before'], 'tags_after' => $tags['after']]);
        $this->saveTags($orderNumber, $tags['after']);
        $this->forgetOldTags([$orderNumber]);

        $this->exclusive->takeOff($cra, Lead::today(), collect([$lead->fresh()]), $by, ExclusiveRemovedLead::VIA_WEBSITE);
    }

    /**
     * Note that the website looks after this lead's tag (the Tag column's Add tag, or tagNext()).
     *
     * @param  array<string, mixed>  $values
     */
    public function note(User $cra, Lead $lead, array $values): void
    {
        ExclusiveLeadTag::updateOrCreate(['lead_id' => $lead->id], ['user_id' => $cra->id, ...$values]);
    }

    /**
     * Pancake confirmed the tag is off these orders: tagging one again is a supervisor's (ExclusiveOldTag).
     *
     * @param  list<string>  $orderNumbers
     */
    private function forgetOldTags(array $orderNumbers): void
    {
        if ($orderNumbers !== []) {
            ExclusiveOldTag::whereIn('pancake_order_id', $orderNumbers)->delete();
        }
    }

    /**
     * @param  list<int>  $tags
     */
    private function saveTags(string $orderNumber, array $tags): void
    {
        PancakeOrder::where('pancake_order_id', $orderNumber)->first()?->update([
            'tags' => $tags,
            'conversion_type' => PancakeOrder::conversionTypeFor($tags),
        ]);
    }

    /**
     * Leads that are hers: assigned to her, or held for her days ahead.
     *
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    private function hers(User $cra, Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('assigned_to', $cra->id)
            ->orWhereIn('id', ExclusiveReservedLead::where('user_id', $cra->id)->select('lead_id')));
    }
}
