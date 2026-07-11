<?php

namespace App\Models;

use App\Support\WeekBlocks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class Cra extends Model
{
    protected $fillable = [
        'name',
        'email',
    ];

    public function pcAssignments(): HasMany
    {
        return $this->hasMany(CraPcAssignment::class);
    }

    public function callStats(): HasMany
    {
        return $this->hasMany(CraCallStat::class);
    }

    public function callStatForDate(Carbon $date): ?CraCallStat
    {
        return $this->callStats->firstWhere('date', $date->toDateString());
    }

    /**
     * This CRA's cohort entries for the 7-day block containing $date — only
     * PCs with an explicitly-entered cohort for that exact week show up
     * here (no carrying forward from previous weeks).
     *
     * @return Collection<int, CraPcAssignment>
     */
    public function assignmentsForWeek(Carbon $date): Collection
    {
        $weekStart = WeekBlocks::startOf($date)->toDateString();

        return $this->pcAssignments->where('week_start', $weekStart)->values();
    }

    /**
     * Every PC (from $allPcs) this CRA has NOT yet entered a cohort for in
     * the 7-day block containing $date — what the "set your cohort" prompt
     * needs filled in.
     *
     * @param  Collection<int, Pc>  $allPcs
     * @return Collection<int, Pc>
     */
    public function missingPcsForWeek(Carbon $date, Collection $allPcs): Collection
    {
        $coveredPcIds = $this->assignmentsForWeek($date)->pluck('pc_id');

        return $allPcs->reject(fn (Pc $pc) => $coveredPcIds->contains($pc->id))->values();
    }
}
