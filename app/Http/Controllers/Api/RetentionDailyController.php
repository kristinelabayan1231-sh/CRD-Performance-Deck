<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RetentionDaily;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/external/v1/retention/daily?from=Y-m-d&to=Y-m-d with a key from Settings → API Keys: CRD's leads, orders,
 * sales and statuses per day (RetentionDaily has the rules), in Philippine time and pesos.
 */
class RetentionDailyController extends Controller
{
    public function __invoke(Request $request, RetentionDaily $report): JsonResponse
    {
        $range = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $timezone = config('segmentation.timezone');
        $from = CarbonImmutable::parse($range['from'], $timezone);
        $to = CarbonImmutable::parse($range['to'], $timezone);

        if ($from->diffInDays($to) + 1 > RetentionDaily::MAX_DAYS) {
            return response()->json([
                'message' => 'Ask for at most '.RetentionDaily::MAX_DAYS.' days at a time.',
                'errors' => ['to' => ['The range may be at most '.RetentionDaily::MAX_DAYS.' days.']],
            ], 422);
        }

        return response()->json([
            'data' => $report->days($from, $to),
            'meta' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'timezone' => $timezone,
                'currency' => 'PHP',
                'generatedAt' => now($timezone)->toIso8601String(),
            ],
        ]);
    }
}
