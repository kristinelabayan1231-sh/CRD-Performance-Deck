<?php

namespace App\Services;

use App\Models\CraPcAssignment;
use App\Support\WeekBlocks;
use Illuminate\Support\Carbon;

class CraCohortService
{
    /**
     * Upsert a CRA's cohort for each given PC, all for the 7-day block
     * containing $weekStartInput. Rows with "To" before "From" are skipped.
     * An entry flagged no_cohort is saved with all cohort fields NULL —
     * an explicit "this PC has no cohort this week", as opposed to no row
     * at all (still "not set", which keeps the weekly prompt nagging).
     *
     * @param  array<int, array{pc_id: int, no_cohort?: mixed, cohort_from_month?: int, cohort_from_year?: int, cohort_to_month?: int, cohort_to_year?: int}>  $pcEntries
     * @return int Number of cohorts saved.
     */
    public function saveWeeklyCohorts(int $craId, string $weekStartInput, array $pcEntries): int
    {
        $weekStart = WeekBlocks::startOf(Carbon::parse($weekStartInput))->toDateString();
        $saved = 0;

        foreach ($pcEntries as $entry) {
            $noCohort = ! empty($entry['no_cohort']);

            if (! $noCohort) {
                $from = Carbon::create($entry['cohort_from_year'], $entry['cohort_from_month'], 1);
                $to = Carbon::create($entry['cohort_to_year'], $entry['cohort_to_month'], 1);

                if ($to->lt($from)) {
                    continue;
                }
            }

            CraPcAssignment::updateOrCreate(
                ['cra_id' => $craId, 'pc_id' => $entry['pc_id'], 'week_start' => $weekStart],
                [
                    'cohort_from_year' => $noCohort ? null : $entry['cohort_from_year'],
                    'cohort_from_month' => $noCohort ? null : $entry['cohort_from_month'],
                    'cohort_to_year' => $noCohort ? null : $entry['cohort_to_year'],
                    'cohort_to_month' => $noCohort ? null : $entry['cohort_to_month'],
                ],
            );
            $saved++;
        }

        return $saved;
    }
}
