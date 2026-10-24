<?php

namespace App\Http\Controllers;

use App\Models\DutySchedule;
use App\Models\Role;
use App\Support\OnDuty;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DutyScheduleController extends Controller
{
    /**
     * A CRA sets the dates picked in My schedule (on-duty pop-up) as Absent / Leave or Rest Day, or clears them back to
     * on duty. Only today and later, up to the last month the calendar shows; past days are locked.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->role?->slug === Role::CRA, 403);

        $today = WorkingDate::realToday();
        $data = $request->validate([
            'dates' => ['required', 'array', 'max:100'],
            'dates.*' => ['date_format:Y-m-d', 'after_or_equal:'.$today->toDateString(), 'before_or_equal:'.OnDuty::scheduleUntil()->toDateString()],
            'type' => ['required', Rule::in([...array_keys(DutySchedule::TYPES), 'clear'])],
        ], [
            'dates.required' => 'Pick at least one date on the calendar.',
            'dates.*.after_or_equal' => 'Past days are locked: ask your supervisor to change one.',
            'dates.*.before_or_equal' => 'You can plan up to '.OnDuty::scheduleUntil()->format('F Y').'.',
        ]);

        $dates = collect($data['dates'])->unique()->sort()->values();

        if ($data['type'] === 'clear') {
            DutySchedule::where('user_id', $user->id)->whereIn('date', $dates)->delete();
        } else {
            DutySchedule::upsert(
                $dates->map(fn (string $date) => ['user_id' => $user->id, 'date' => $date, 'type' => $data['type'], 'created_at' => now(), 'updated_at' => now()])->all(),
                ['user_id', 'date'],
                ['type', 'updated_at'],
            );
        }

        $label = $dates->map(fn (string $date) => CarbonImmutable::parse($date)->format('M j'))->join(', ', ' and ');
        $as = $data['type'] === 'clear' ? 'back to on duty' : 'as '.DutySchedule::TYPES[$data['type']];

        return back()->with('status', "My schedule saved: {$label} set {$as}.")->with('schedule_open', true);
    }
}
