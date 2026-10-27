<?php

namespace App\Models;

use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'order_id', 'tracking_number', 'customer_name', 'phone_number', 'product_name', 'product_raw', 'qty', 'qty_unknown',
    'delivered_date', 'consumption_days', 'est_out_of_stock_date', 'listed_on', 'lead_type',
    'assigned_to', 'assigned_at', 'status', 'status_updated_by', 'status_updated_at',
    'repeat_purchase', 'repeat_purchase_on', 'customer_tag', 'contact_date', 'contact_time', 'feedback', 'callback_date',
    'call_recording_url', 'notes', 'notes_updated_by', 'notes_updated_at',
])]
class Lead extends Model
{
    public const TYPE_CRD = 'crd';

    public const TYPE_FSD = 'fsd';

    public const TYPES = [
        self::TYPE_CRD => 'CRD Lead',
        self::TYPE_FSD => 'FSD Lead',
    ];

    /** Order # prefix of a lead added from a CRA's Pancake POS order (PosLeads), not from the retention report. */
    public const POS_PREFIX = 'POS-';

    /**
     * The lead day (listed_on) follows the out-of-stock date until the backlog fill moves the lead.
     * Repeat Purchase set to Yes dates the purchase unless a date was given: the real date, not the working
     * date (a September lead bought on Oct 16 counts on Oct 16). Anything else clears it.
     */
    protected static function booted(): void
    {
        static::saving(function (Lead $lead) {
            // Listed on its out-of-stock date until the backlog fill moves it; a moved lead stays where it was moved.
            $moved = $lead->exists && $lead->getOriginal('listed_on') !== null
                && ! $lead->getOriginal('listed_on')->isSameDay($lead->getOriginal('est_out_of_stock_date'));

            if ($lead->listed_on === null || ($lead->isDirty('est_out_of_stock_date') && ! $lead->isDirty('listed_on') && ! $moved)) {
                $lead->listed_on = $lead->est_out_of_stock_date;
            }

            if (! $lead->isDirty('repeat_purchase')) {
                return;
            }

            $lead->repeat_purchase_on = $lead->repeat_purchase === 'yes' ? ($lead->repeat_purchase_on ?? WorkingDate::realToday()) : null;
        });
    }

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'qty_unknown' => 'boolean',
            'consumption_days' => 'integer',
            'delivered_date' => 'immutable_date',
            'est_out_of_stock_date' => 'immutable_date',
            'listed_on' => 'immutable_date',
            'assigned_at' => 'datetime',
            'status_updated_at' => 'datetime',
            'processed_at' => 'datetime',
            'repeat_purchase_on' => 'immutable_date',
            'contact_date' => 'immutable_date',
            'callback_date' => 'immutable_date',
            'notes_updated_at' => 'datetime',
        ];
    }

    /**
     * Tracker search: customer name, order number, or contact number (any format: 0917…, +63 917…, 917…).
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $digits = preg_replace('/\D/', '', $term);
        // Phone numbers are stored in mixed formats, so match on the part after 0 / 63.
        $phone = preg_replace('/^(63|0)/', '', $digits);
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        return $query->where(fn (Builder $q) => $q
            // Case-insensitive on every database (Postgres LIKE is case-sensitive).
            ->whereLike('customer_name', $like, caseSensitive: false)
            ->orWhereLike('order_id', $like, caseSensitive: false)
            ->when(strlen($phone) >= 4, fn (Builder $q) => $q->orWhere('phone_number', 'like', '%'.$phone.'%')));
    }

    /**
     * Still to do in the tracker: no status, no contact date, and not marked as processed.
     */
    public function scopeUnprocessed(Builder $query): Builder
    {
        return $query->whereNull('status')->whereNull('contact_date')->whereNull('processed_at');
    }

    /**
     * Never touched by a CRA: unprocessed and every tracking field blank (no note, feedback, Repeat Purchase, tag,
     * time, callback date or recording). Only these leads may be moved by the backlog fill.
     */
    public function scopeUntouched(Builder $query): Builder
    {
        return $query->unprocessed()
            ->whereNull('notes')->whereNull('feedback')->whereNull('repeat_purchase')->whereNull('customer_tag')
            ->whereNull('contact_time')->whereNull('callback_date')->whereNull('call_recording_url');
    }

    public function scopeProcessed(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNotNull('status')->orWhereNotNull('contact_date')->orWhereNotNull('processed_at'));
    }

    public function isProcessed(): bool
    {
        return $this->status !== null || $this->contact_date !== null || $this->processed_at !== null;
    }

    /**
     * What "Unmark processed" clears besides the mark, e.g. "status Active and date of contact Oct 5"; null when nothing.
     */
    public function unmarkClears(): ?string
    {
        $parts = array_filter([
            $this->status !== null ? 'status '.$this->statusLabel() : null,
            $this->contact_date !== null ? 'date of contact '.$this->contact_date->format('M j') : null,
        ]);

        return $parts ? implode(' and ', $parts) : null;
    }

    /**
     * Converted: Repeat Purchase = Yes, or Repeat Purchase = No with feedback PURCHASED.
     */
    public function scopeConverted(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('repeat_purchase', 'yes')
            ->orWhere(fn (Builder $q) => $q->where('repeat_purchase', 'no')->where('feedback', 'purchased')));
    }

    /**
     * The opposite of converted, counting a blank Repeat Purchase or feedback as not converted.
     */
    public function scopeNotConverted(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('repeat_purchase')
            ->orWhere(fn (Builder $q) => $q->where('repeat_purchase', '!=', 'yes')
                ->where(fn (Builder $q) => $q->where('repeat_purchase', '!=', 'no')->orWhereNull('feedback')->orWhere('feedback', '!=', 'purchased'))));
    }

    public function isConverted(): bool
    {
        return $this->repeat_purchase === 'yes'
            || ($this->repeat_purchase === 'no' && $this->feedback === 'purchased');
    }

    /**
     * Leads from the retention report and sheets, without those added from a CRA's POS sale (PosLeads):
     * those are the sale itself, so reports judging how leads convert leave them out.
     */
    public function scopeWithoutPosSales(Builder $query): Builder
    {
        return $query->where('order_id', 'not like', self::POS_PREFIX.'%');
    }

    /**
     * Repeat Purchase = Yes, bought in $from–$to (the day it was set to Yes, not the lead day).
     */
    public function scopeRepeatPurchasedBetween(Builder $query, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return $query->where('repeat_purchase', 'yes')
            ->whereDate('repeat_purchase_on', '>=', $from)
            ->whereDate('repeat_purchase_on', '<=', $to);
    }

    /**
     * Carry-over as of the start of $day: assigned leads from earlier lead days
     * that had no status, or a carry-over status (PJR, Inactive). Rows updated during $day stay listed for that day so they
     * don't vanish while a CRA works on them.
     */
    public function scopeBacklogAsOf(Builder $query, CarbonImmutable $day): Builder
    {
        $dayStarted = CarbonImmutable::parse($day->toDateString(), config('segmentation.timezone'))->utc();

        return $query->whereNotNull('assigned_to')
            ->whereDate('listed_on', '<', $day)
            ->where(fn (Builder $q) => $q->whereNull('status')
                ->orWhereIn('status', config('segmentation.carry_over_statuses'))
                ->orWhere('status_updated_at', '>=', $dayStarted));
    }

    /**
     * Carrying over right now: assigned, past its lead day, and either no
     * status or a carry-over status.
     */
    public function scopeCarryOver(Builder $query, ?CarbonImmutable $today = null): Builder
    {
        return $query->whereNotNull('assigned_to')
            ->whereDate('listed_on', '<', $today ?? self::today())
            ->where(fn (Builder $q) => $q->whereNull('status')->orWhereIn('status', config('segmentation.carry_over_statuses')));
    }

    /**
     * Call back for today: a callback date on or before $today (the real date, as CRAs set it) that hasn't been
     * followed by a contact on or after it, and no purchase yet.
     */
    public function scopeCallbackDue(Builder $query, CarbonImmutable $today): Builder
    {
        return $query->whereNotNull('assigned_to')
            ->whereDate('callback_date', '<=', $today)
            ->where(fn (Builder $q) => $q->whereNull('contact_date')->orWhereColumn('contact_date', '<', 'callback_date'))
            ->notConverted();
    }

    /**
     * Reactivation: a follow-up status (any but segmentation.reactivation_skip_statuses) from an earlier lead day than $leadDay,
     * not on Repeat Purchase = Reserve (it has its own tab), not a cold lead whose attempts are used up,
     * no purchase, not contacted yet on $today (the real date) and no callback date still to come or due.
     */
    public function scopeReactivation(Builder $query, CarbonImmutable $leadDay, CarbonImmutable $today): Builder
    {
        return $query->whereNotNull('assigned_to')
            ->whereDate('listed_on', '<', $leadDay)
            ->whereNotNull('status')->whereNotIn('status', config('segmentation.reactivation_skip_statuses'))
            ->where(fn (Builder $q) => $q->whereNull('repeat_purchase')->orWhereNotIn('repeat_purchase', self::reserveRepeatPurchases()))
            ->whereNot(fn (Builder $q) => $q->coldAttemptsUsed())
            ->notConverted()
            ->where(fn (Builder $q) => $q->whereNull('contact_date')->orWhereDate('contact_date', '<', $today))
            ->where(fn (Builder $q) => $q->whereNull('callback_date')->orWhereColumn('contact_date', '>=', 'callback_date'));
    }

    /**
     * Reserved: Repeat Purchase = Reserve (Lead::reserveRepeatPurchases()) from any lead day. Listed in its own tab of the Pending full view.
     */
    public function scopeReserved(Builder $query): Builder
    {
        return $query->whereNotNull('assigned_to')->whereIn('repeat_purchase', self::reserveRepeatPurchases());
    }

    /**
     * Repeat Purchase keys that mean Reserve: the "reserve" / "reserved" key or a choice labelled Reserve / Reserved,
     * so a renamed one in Settings → Segmentation Tracker still counts.
     *
     * @return list<string>
     */
    public static function reserveRepeatPurchases(): array
    {
        return collect(config('segmentation.repeat_purchase'))
            ->filter(fn (array|string $option, int|string $key) => in_array(strtolower((string) $key), ['reserve', 'reserved'], true)
                || preg_match('/^\s*reserved?\s*$/i', is_array($option) ? $option[0] : $option))
            ->keys()->map(fn (int|string $key) => (string) $key)->values()->all();
    }

    /**
     * Cold leads (segmentation.cold_tags) whose segmentation.cold_attempts are all logged.
     */
    public function scopeColdAttemptsUsed(Builder $query): Builder
    {
        return $query->whereIn('customer_tag', config('segmentation.cold_tags'))
            ->has('attempts', '>=', (int) config('segmentation.cold_attempts'));
    }

    /**
     * Cold leads still being retried: attempts left and no Active status yet. Left out of the churn rate.
     */
    public function scopeColdRetrying(Builder $query): Builder
    {
        return $query->whereIn('customer_tag', config('segmentation.cold_tags'))
            ->where(fn (Builder $q) => $q->whereNull('status')->orWhere('status', '!=', 'active'))
            ->has('attempts', '<', (int) config('segmentation.cold_attempts'));
    }

    public function isCold(): bool
    {
        return in_array($this->customer_tag, config('segmentation.cold_tags'), true);
    }

    /**
     * Contact attempts still to make on a cold lead (never below 0). Uses the loaded attempts when there are.
     */
    public function attemptsLeft(): int
    {
        return max(0, (int) config('segmentation.cold_attempts') - $this->attempts->count());
    }

    public function carriesOver(): bool
    {
        return $this->status === null || in_array($this->status, config('segmentation.carry_over_statuses'), true);
    }

    /**
     * Calls uploaded from the CRAs' phones that matched this lead's contact number, latest first.
     */
    public function callRecordings(): HasMany
    {
        return $this->hasMany(CallRecording::class)->latest('called_at');
    }

    /**
     * Cold lead contact attempts, oldest first.
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(LeadAttempt::class)->orderBy('attempted_on');
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(LeadTransfer::class)->latest('id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Hand the lead to $to from $day on: listed on $day for them, while its old day keeps counting it for the CRA
     * who had it (LeadMove), so past days read the same. Logged as a transfer by $by.
     */
    public function transferTo(User $to, User $by, CarbonImmutable $day): void
    {
        BacklogHandout::create(['lead_id' => $this->id, 'user_id' => $to->id, 'handed_on' => $day->toDateString(), 'source' => BacklogHandout::TRANSFER]);
        LeadMove::create(['lead_id' => $this->id, 'assigned_to' => $this->assigned_to, 'listed_on' => $this->listed_on->toDateString(), 'moved_to' => $day->toDateString()]);
        LeadTransfer::create(['lead_id' => $this->id, 'from_user_id' => $this->assigned_to, 'to_user_id' => $to->id, 'transferred_by' => $by->id]);

        $this->forceFill(['assigned_to' => $to->id, 'assigned_at' => now(), 'listed_on' => $day->toDateString()])->save();
    }

    public function notesAuthor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'notes_updated_by');
    }

    /**
     * Today's calendar date in the segmentation timezone, as a plain date in
     * the app timezone so it compares cleanly with stored dates.
     */
    /**
     * The app's "today": Settings → Working Date when set, else the real date.
     */
    public static function today(): CarbonImmutable
    {
        return WorkingDate::get() ?? WorkingDate::realToday();
    }

    public function daysSinceDelivered(): int
    {
        return (int) $this->delivered_date->diffInDays(self::today());
    }

    public function recommendedReplenishmentDay(): CarbonImmutable
    {
        return $this->est_out_of_stock_date->subDays(config('segmentation.replenishment_days_before'));
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->lead_type] ?? $this->lead_type;
    }

    public function statusLabel(): ?string
    {
        return self::optionLabel('statuses', $this->status);
    }

    public function statusClasses(): string
    {
        return self::optionClasses('statuses', $this->status);
    }

    /**
     * Label for a value in one of the segmentation dropdown lists.
     */
    public static function optionLabel(string $list, ?string $key): ?string
    {
        $option = $key === null ? null : config("segmentation.{$list}.{$key}");

        return is_array($option) ? $option[0] : $option;
    }

    public static function optionClasses(string $list, ?string $key): string
    {
        $option = $key === null ? null : config("segmentation.{$list}.{$key}");

        return is_array($option) ? $option[1] : 'bg-canvas text-muted';
    }
}
