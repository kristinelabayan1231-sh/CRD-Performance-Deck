<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class CraAssignment extends Model
{
    protected $fillable = [
        'cra_id',
        'facebook_page_id',
        'year',
        'month',
        'week',
    ];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'week' => 'integer',
    ];

    public function cra(): BelongsTo
    {
        return $this->belongsTo(Cra::class);
    }

    public function facebookPage(): BelongsTo
    {
        return $this->belongsTo(FacebookPage::class);
    }

    /**
     * Months are sliced into fixed 7-day blocks starting on day 1
     * (1–7, 8–14, 15–21, 22–28, 29–end), not calendar weeks.
     */
    public function periodStart(): Carbon
    {
        return Carbon::create($this->year, $this->month, 1)
            ->startOfMonth()
            ->addDays(($this->week - 1) * 7);
    }

    public function periodEnd(): Carbon
    {
        $monthEnd = Carbon::create($this->year, $this->month, 1)->endOfMonth();
        $candidateEnd = $this->periodStart()->copy()->addDays(6);

        return $candidateEnd->greaterThan($monthEnd) ? $monthEnd : $candidateEnd;
    }

    public function label(): string
    {
        $start = $this->periodStart();
        $end = $this->periodEnd();

        return $start->format('M j') . '–' . $end->format($start->month === $end->month ? 'j, Y' : 'M j, Y');
    }

    /**
     * The full calendar month this assignment falls in. Data attribution is
     * month-wide — the "week" is just a marker of when the assignment was
     * made, not a filter on the Pancake date range.
     */
    public function monthStart(): Carbon
    {
        return Carbon::create($this->year, $this->month, 1)->startOfMonth();
    }

    public function monthEnd(): Carbon
    {
        return $this->monthStart()->copy()->endOfMonth();
    }

    public function monthLabel(): string
    {
        return $this->monthStart()->format('F Y');
    }
}
