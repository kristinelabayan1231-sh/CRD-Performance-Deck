<?php

namespace App\Http\Controllers;

use App\Models\ExclusiveLeadLimit;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Exclusive Lead Tracker → Settings: the leads a day, leads a month and orders a day from a date on. Today or later,
 * so days already worked keep their goals; saving a date again replaces what it had.
 */
class ExclusiveLeadLimitController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'effective_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.WorkingDate::realToday()->toDateString()],
            'daily_leads' => ['required', 'integer', 'min:1', 'max:2000'],
            'month_target' => ['required', 'integer', 'min:1', 'max:100000'],
            'orders_per_day' => ['required', 'integer', 'min:1', 'max:1000'],
        ], ['effective_from.after_or_equal' => 'New limits can start today or later.']);

        (ExclusiveLeadLimit::whereDate('effective_from', $data['effective_from'])->first() ?? new ExclusiveLeadLimit)
            ->fill([...$data, 'set_by' => $request->user()->id])
            ->save();

        return back()->with('status', 'Exclusive Lead Tracker limits saved, starting '.CarbonImmutable::parse($data['effective_from'])->format('M j, Y').'.');
    }

    /**
     * Only a change that hasn't started yet can be taken back.
     */
    public function destroy(ExclusiveLeadLimit $limit): RedirectResponse
    {
        abort_unless($limit->effective_from->greaterThan(WorkingDate::realToday()), 422, 'These limits have already started.');

        $limit->delete();

        return back()->with('status', 'Exclusive Lead Tracker limits for '.$limit->effective_from->format('M j, Y').' removed.');
    }
}
