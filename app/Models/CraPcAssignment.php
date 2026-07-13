<?php

namespace App\Models;

use App\Support\WeekBlocks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class CraPcAssignment extends Model
{
    protected $fillable = [
        'cra_id',
        'pc_id',
        'week_start',
        'cohort_from_year',
        'cohort_from_month',
        'cohort_to_year',
        'cohort_to_month',
    ];

    // week_start is stored and compared as a plain Y-m-d string.
    protected $casts = [
        'cohort_from_year' => 'integer',
        'cohort_from_month' => 'integer',
        'cohort_to_year' => 'integer',
        'cohort_to_month' => 'integer',
    ];

    public function cra(): BelongsTo
    {
        return $this->belongsTo(Cra::class);
    }

    public function pc(): BelongsTo
    {
        return $this->belongsTo(Pc::class);
    }

    public function weekStartDate(): Carbon
    {
        return Carbon::parse($this->week_start);
    }

    public function weekEndDate(): Carbon
    {
        return $this->weekStartDate()->copy()->addDays(6);
    }

    public function weekLabel(): string
    {
        return WeekBlocks::label($this->weekStartDate());
    }

    /**
     * A row saved with all cohort fields NULL means the CRA explicitly
     * works this PC without a cohort this week — it counts as "set" (no
     * weekly prompt nag) but is skipped by inquiry syncing and the CRA
     * Performance day tables.
     */
    public function hasCohort(): bool
    {
        return $this->cohort_from_year !== null
            && $this->cohort_from_month !== null
            && $this->cohort_to_year !== null
            && $this->cohort_to_month !== null;
    }

    public function cohortStart(): Carbon
    {
        return Carbon::create($this->cohort_from_year, $this->cohort_from_month, 1)->startOfMonth();
    }

    public function cohortEnd(): Carbon
    {
        return Carbon::create($this->cohort_to_year, $this->cohort_to_month, 1)->endOfMonth();
    }

    public function cohortLabel(): string
    {
        if (! $this->hasCohort()) {
            return '—';
        }

        $start = $this->cohortStart();
        $end = $this->cohortEnd();

        if ($start->isSameMonth($end)) {
            return $start->format('M Y');
        }

        return $start->format('M Y') . ' – ' . $end->format('M Y');
    }

    /**
     * Whether a customer created at the given time belongs to this cohort.
     */
    public function cohortContains(Carbon $customerCreatedAt): bool
    {
        return $customerCreatedAt->between($this->cohortStart(), $this->cohortEnd());
    }
}
