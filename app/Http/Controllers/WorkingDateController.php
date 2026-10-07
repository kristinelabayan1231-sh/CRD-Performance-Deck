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
     * Set the day the app treats as today; blank goes back to the real date.
     */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'working_date' => ['nullable', 'date', 'before_or_equal:'.WorkingDate::realToday()->toDateString()],
        ], [], ['working_date' => 'working date']);

        $date = $data['working_date'] ? CarbonImmutable::parse($data['working_date']) : null;
        WorkingDate::set($date, $request->user());

        return back()->with('status', $date
            ? "Working date set to {$date->format('M j, Y')}. The whole app now shows that day as today."
            : 'Working date cleared. The app follows the real date again.');
    }
}
