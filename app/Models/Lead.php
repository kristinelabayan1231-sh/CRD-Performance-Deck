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
    'delivered_date', 'consumption_days', 'est_out_of_stock_date', 'lead_type',
    'assigned_to', 'assigned_at', 'status', 'status_updated_by', 'status_updated_at',
    'repeat_purchase', 'customer_tag', 'contact_date', 'contact_time', 'feedback', 'callback_date',
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

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'qty_unknown' => 'boolean',
            'consumption_days' => 'integer',
            'delivered_date' => 'immutable_date',
            'est_out_of_stock_date' => 'immutable_date',
            'assigned_at' => 'datetime',
            'status_updated_at' => 'datetime',
            'processed_at' => 'datetime',
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

    public function isConverted(): bool
    {
        return $this->repeat_purchase === 'yes'
            || ($this->repeat_purchase === 'no' && $this->feedback === 'purchased');
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
            ->whereDate('est_out_of_stock_date', '<', $day)
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
            ->whereDate('est_out_of_stock_date', '<', $today ?? self::today())
            ->where(fn (Builder $q) => $q->whereNull('status')->orWhereIn('status', config('segmentation.carry_over_statuses')));
    }

    public function carriesOver(): bool
    {
        return $this->status === null || in_array($this->status, config('segmentation.carry_over_statuses'), true);
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(LeadTransfer::class)->latest('id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
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
