<?php

namespace App\Models;

use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Database\Factories\PancakeReservationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer who reserved in a Pancake conversation, noted by a CRA in the Pending full view's Reserved - Pancake tab
 * (Pancake's chats can't be fetched for these). Same tracking fields as a lead, but not a lead: no lead count changes.
 * Qty to Type are the customer's last order when one was found (PancakeReservationLookup), else blank.
 */
#[Fillable([
    'assigned_to', 'created_by', 'customer_name', 'phone_number', 'phone_key',
    'product_name', 'qty', 'delivered_date', 'consumption_days', 'est_out_of_stock_date', 'lead_type', 'filled_from',
    'status', 'status_updated_by', 'status_updated_at',
    'repeat_purchase', 'repeat_purchase_on', 'customer_tag', 'contact_date', 'contact_time', 'feedback', 'callback_date',
    'notes', 'notes_updated_by', 'notes_updated_at',
])]
class PancakeReservation extends Model
{
    /** @use HasFactory<PancakeReservationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'consumption_days' => 'integer',
            'delivered_date' => 'immutable_date',
            'est_out_of_stock_date' => 'immutable_date',
            'status_updated_at' => 'datetime',
            'repeat_purchase_on' => 'immutable_date',
            'contact_date' => 'immutable_date',
            'callback_date' => 'immutable_date',
            'notes_updated_at' => 'datetime',
        ];
    }

    /**
     * Repeat Purchase set to Yes dates the purchase (the real date), as on a lead; anything else clears it.
     */
    protected static function booted(): void
    {
        static::saving(function (PancakeReservation $reservation) {
            if ($reservation->isDirty('repeat_purchase')) {
                $reservation->repeat_purchase_on = $reservation->repeat_purchase === 'yes' ? WorkingDate::realToday() : null;
            }
        });
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function notesAuthor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'notes_updated_by');
    }

    public function daysSinceDelivered(): ?int
    {
        return $this->delivered_date ? (int) $this->delivered_date->diffInDays(Lead::today()) : null;
    }

    public function recommendedReplenishmentDay(): ?CarbonImmutable
    {
        return $this->est_out_of_stock_date?->subDays(config('segmentation.replenishment_days_before'));
    }

    public function typeLabel(): ?string
    {
        return $this->lead_type === null ? null : (Lead::TYPES[$this->lead_type] ?? $this->lead_type);
    }
}
