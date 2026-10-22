<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\LeadGenerator;
use App\Services\PancakeSync;
use App\Services\RunningSales;
use App\Support\WorkingDate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class RunningSalesController extends Controller
{
    public function index(Request $request, RunningSales $runningSales): View
    {
        $filters = $this->filters($request);
        $snapshot = $runningSales->snapshot($filters['period']);

        return view('running-sales.index', [
            ...$filters,
            'snapshot' => $snapshot,
            // For their photos; the snapshot keeps names only.
            'users' => User::findMany(array_column($snapshot['cras'], 'id'))->keyBy('id'),
            'nextFetch' => RunningSales::nextFetch(),
            'missingAccounts' => LeadGenerator::cras()->filter(fn (User $cra) => ! $cra->pancake_name)->values(),
        ]);
    }

    /**
     * Fetch today's new Pancake orders, then take every period's snapshot again.
     */
    public function refresh(Request $request, RunningSales $runningSales, PancakeSync $pancake): RedirectResponse
    {
        $status = 'Running sales refreshed.';

        try {
            $pancake->syncNewOrders(WorkingDate::realToday());
        } catch (Throwable $e) {
            report($e);
            $status = 'Pancake did not respond, so the running sales use the orders already saved.';
        }

        $runningSales->refresh();

        return redirect()->route('running-sales.index', $this->filters($request))->with('status', $status);
    }

    /**
     * @return array{period: string, show: string}
     */
    private function filters(Request $request): array
    {
        $filters = $request->validate([
            'period' => ['nullable', Rule::in(array_keys(RunningSales::PERIODS))],
            'show' => ['nullable', Rule::in(['sales', 'qty'])],
        ]);

        return ['period' => $filters['period'] ?? 'today', 'show' => $filters['show'] ?? 'sales'];
    }
}
