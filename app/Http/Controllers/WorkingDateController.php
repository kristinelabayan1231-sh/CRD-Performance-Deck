<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkingDateController extends Controller
{
    public function index(): View
    {
        return view('settings.working-date', [
            'workingDate' => WorkingDate::get(),
            'realToday' => WorkingDate::realToday(),
            'lastChange' => Setting::where('key', WorkingDate::KEY)->with('editor')->first(),
        ]);
    }

    /**
     * Set the working date that starts tomorrow; blank goes back to the real date.
     */
    public function update(Request $request): RedirectResponse
    {
        $tomorrow = WorkingDate::realToday()->addDay();

        $data = $request->validate([
            'start' => ['nullable', 'date', 'before_or_equal:'.$tomorrow->toDateString()],
        ], [], ['start' => 'start of working date']);

        $start = $data['start'] ? CarbonImmutable::parse($data['start']) : null;
        WorkingDate::startTomorrow($start, $request->user());

        return back()->with('status', $start
            ? "Working date starts at {$start->format('M j, Y')} tomorrow ({$tomorrow->format('M j')}) and moves forward one day each day. Today shows {$start->subDay()->format('M j')}."
            : 'Working date cleared. The app follows the real date again.');
    }
}
