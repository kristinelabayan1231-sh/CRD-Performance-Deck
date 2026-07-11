<?php

namespace App\Services;

use App\Models\CraCallStat;

class CraCallStatService
{
    public function saveDailyStats(int $craId, string $date, int $totalCalls, int $answeredCalls): CraCallStat
    {
        return CraCallStat::updateOrCreate(
            ['cra_id' => $craId, 'date' => $date],
            ['total_calls' => $totalCalls, 'answered_calls' => $answeredCalls],
        );
    }
}
