<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MonthlyCostSnapshot;
use App\Services\McPayroll;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/external/v1/crd/mc/summary|roster?month=YYYY-MM with an MC / salaries key from Settings → API Keys: CRD's
 * payroll for the month (McPayroll has the rules), in pesos. The roster has names; neither has anything else personal.
 */
class McPayrollController extends Controller
{
    public function summary(Request $request, McPayroll $payroll): JsonResponse
    {
        return $this->respond($request, $payroll, fn ($snapshot) => McPayroll::summaryOf($snapshot));
    }

    public function roster(Request $request, McPayroll $payroll): JsonResponse
    {
        return $this->respond($request, $payroll, fn ($snapshot) => McPayroll::rosterOf($snapshot));
    }

    /**
     * @param  callable(MonthlyCostSnapshot): array<string, mixed>  $shape
     */
    private function respond(Request $request, McPayroll $payroll, callable $shape): JsonResponse
    {
        $month = $request->validate(['month' => ['required', 'date_format:Y-m']])['month'];
        $snapshot = $payroll->forMonth($month);

        if (! $snapshot) {
            return response()->json([
                'message' => $month > McPayroll::currentMonth()
                    ? 'That month has not started yet.'
                    : 'No figures were saved for that month: the API keeps months from when it was turned on.',
            ], 404);
        }

        return response()->json([
            'data' => $shape($snapshot),
            'meta' => [
                'department' => McPayroll::DEPARTMENT,
                'timezone' => config('segmentation.timezone'),
                'currency' => 'PHP',
                'generatedAt' => now(config('segmentation.timezone'))->toIso8601String(),
            ],
        ]);
    }
}
