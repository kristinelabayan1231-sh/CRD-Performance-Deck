<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One customer's text for one day, for their CRA: a reminder for their leads on the CRA's tracker list that day
 * (lead_ids; their products in one text, covers) or a follow-up
 * (1 to 3, 7 days after the last text while they haven't reordered; automatic, or scheduled by the CRA). Pending →
 * (ticked) queued at the send time or on Send now → sent from the CRA's phone; or left out by the CRA (excluded), or
 * skipped by the app (opted out, reordered, already texted), or failed.
 */
#[Fillable([
    'phone_key', 'phone_number', 'customer_name', 'product_id', 'products', 'customer_type', 'run_out_on', 'remind_on',
    'user_id', 'status', 'excluded_reason', 'status_updated_by', 'sms_template_id', 'message', 'attempts',
    'next_attempt_at', 'queued_at', 'handed_at', 'sent_at', 'error', 'kind', 'follow_up_number', 'covers', 'lead_ids', 'scheduled_by',
    'note', 'no_reorder_reason', 'reordered_at', 'no_response_at', 'resolved_at',
])]
class RestockReminder extends Model
{
    public const PENDING = 'pending';

    public const EXCLUDED = 'excluded';

    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    public const REMINDER = 'reminder';

    public const FOLLOW_UP = 'follow_up';

    /** Automatic follow-ups before a customer who hasn't reordered moves to No response. */
    public const MAX_FOLLOW_UPS = 3;

    /** Days after the last text a follow-up goes out. */
    public const FOLLOW_UP_DAYS = 7;

    /** Statuses that mean the customer was (or will be) texted, or the CRA chose not to: a run-out they cover isn't texted again. */
    public const COVERING = [self::PENDING, self::QUEUED, self::SENT, self::EXCLUDED];

    /** Status => [label, badge classes]. */
    public const STATUSES = [
        self::PENDING => ['Will be texted', 'bg-[#fbeccd] text-[#7a4d00]'],
        self::QUEUED => ['Sending…', 'bg-[#dde9f7] text-[#1a4f8c]'],
        self::SENT => ['Sent', 'bg-[#d4edbc] text-[#11734b]'],
        self::EXCLUDED => ['Left out', 'bg-[#e6e6e6] text-[#3d3d3d]'],
        self::SKIPPED => ['Skipped', 'bg-[#e6e6e6] text-[#3d3d3d]'],
        self::FAILED => ['Failed', 'bg-[#fde0e0] text-[#a3271f]'],
    ];

    /** Reasons a CRA can pick when leaving a customer out. */
    public const EXCLUDE_REASONS = [
        'Talked to them already',
        'Already ordered',
        'Asked not to be texted',
        'Other',
    ];

    protected function casts(): array
    {
        return [
            'run_out_on' => 'immutable_date',
            'remind_on' => 'immutable_date',
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'queued_at' => 'datetime',
            'handed_at' => 'datetime',
            'sent_at' => 'datetime',
            'covers' => 'array',
            'lead_ids' => 'array',
            'follow_up_number' => 'integer',
            'reordered_at' => 'datetime',
            'no_response_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * Still to send on $day: not sent yet (pending or queued), from $day or an earlier day. A text not sent on its day
     * (the phone's daily cap, no phone yet, sending off) carries over to the next days until it goes out or is skipped.
     */
    public function scopeToSendOn(Builder $query, CarbonImmutable $day): Builder
    {
        return $query->whereIn('status', [self::PENDING, self::QUEUED])
            ->whereDate('remind_on', '<=', $day->toDateString());
    }

    /**
     * Follow-ups still to send (scheduled ahead, due or carried over).
     */
    public function scopeFollowUpsToSend(Builder $query): Builder
    {
        return $query->where('kind', self::FOLLOW_UP)->whereIn('status', [self::PENDING, self::QUEUED]);
    }

    /**
     * Customers on No response: no reorder after the reminder and every automatic follow-up, not handled yet.
     */
    public function scopeNoResponse(Builder $query): Builder
    {
        return $query->whereNotNull('no_response_at')->whereNull('resolved_at');
    }

    public function isFollowUp(): bool
    {
        return $this->kind === self::FOLLOW_UP;
    }

    /**
     * What the text is for: [label, detail], e.g. ["Follow-up 1 of 3", "Automatic"] or ["Tracker list", "Out of stock Oct 1 and Oct 2"].
     *
     * @return array{0: string, 1: string}
     */
    public function why(): array
    {
        if ($this->isFollowUp()) {
            $label = $this->scheduled_by ? 'Follow-up' : 'Follow-up '.$this->follow_up_number.' of '.self::MAX_FOLLOW_UPS;

            return [$label, $this->scheduled_by ? 'Scheduled by '.($this->scheduler?->displayName() ?? 'a CRA') : 'Automatic, no reorder yet'];
        }

        $days = collect($this->coveredItems())->pluck('run_out_on')->unique()->map(fn (string $day) => CarbonImmutable::parse($day)->format('M j'));

        return ['Tracker list', 'Out of stock '.$days->join(', ', ' and ').($days->count() > 1 ? ': one text for all' : '')];
    }

    /**
     * Every product and run-out the text is for (texts saved before covers: its first product and run-out).
     *
     * @return list<array{product_id: ?int, name: string, run_out_on: string}>
     */
    public function coveredItems(): array
    {
        return $this->covers ?: [['product_id' => $this->product_id, 'name' => $this->products, 'run_out_on' => $this->run_out_on->toDateString()]];
    }

    /**
     * The products for {product}: "Clear Sight 3.0", "Clear Sight 3.0 at Sinuxyl", "A, B at C".
     */
    public function productsPhrase(): string
    {
        $names = collect($this->coveredItems())->pluck('name')->unique()->values();

        return $names->count() > 1 ? $names->slice(0, -1)->join(', ').' at '.$names->last() : (string) $names->first();
    }

    /**
     * The run-out for {run_out_date}: "1 Dec", "1–2 Dec", "30 Nov–2 Dec".
     */
    public function runOutPhrase(): string
    {
        $days = collect($this->coveredItems())->pluck('run_out_on')->map(fn (string $day) => CarbonImmutable::parse($day))->sort()->values();
        [$first, $last] = [$days->first() ?? $this->run_out_on, $days->last() ?? $this->run_out_on];

        return match (true) {
            $first->isSameDay($last) => $first->format('j M'),
            $first->isSameMonth($last) => $first->format('j').'–'.$last->format('j M'),
            default => $first->format('j M').'–'.$last->format('j M'),
        };
    }

    public function scheduler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scheduled_by');
    }

    /**
     * A reminder day's list: its own customers, and from earlier days those still to send (scopeToSendOn).
     */
    public function scopeShownOn(Builder $query, CarbonImmutable $day): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereDate('remind_on', $day->toDateString())
            ->orWhere(fn (Builder $q) => $q->toSendOn($day)->whereDate('remind_on', '<', $day->toDateString())));
    }

    /**
     * Carried over from an earlier reminder day onto $day's list.
     */
    public function isCarriedOverTo(CarbonImmutable $day): bool
    {
        return $this->remind_on->lt($day->startOfDay());
    }

    public function cra(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Follow-up texts sent by hand to this customer.
     */
    public function manualMessages(): HasMany
    {
        return $this->hasMany(SmsMessage::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(SmsTemplate::class, 'sms_template_id');
    }

    /**
     * Reminders the CRA can still leave out or put back: not yet sent, skipped or failed.
     */
    public function scopeChangeable(Builder $query): void
    {
        $query->whereIn('status', [self::PENDING, self::QUEUED, self::EXCLUDED]);
    }

    public function isChangeable(): bool
    {
        return in_array($this->status, [self::PENDING, self::QUEUED, self::EXCLUDED], true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? $this->status;
    }

    public function statusClasses(): string
    {
        return self::STATUSES[$this->status][1] ?? '';
    }

    public function customerTypeLabel(): string
    {
        return config('sms_reminders.customer_types')[$this->customer_type] ?? $this->customer_type;
    }

    public function firstName(): string
    {
        return ucfirst(mb_strtolower(strtok(trim((string) $this->customer_name), ' ') ?: ''));
    }
}
