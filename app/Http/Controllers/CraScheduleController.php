<?php

namespace App\Http\Controllers;

use App\Models\DutyLogin;
use App\Models\DutySchedule;
use App\Models\User;
use App\Services\LeadGenerator;
use App\Support\DutyGoals;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CraScheduleController extends Controller
{
    /**
     * CRA Schedule: a month calendar of every CRA's days off (set in My schedule, or not logged in while others were)
     * and log-ins, each CRA's totals, and the picked day's CRAs, which on_duty.manage can change, past days included.
     */
    public function index(Request $request): View
    {
        $today = WorkingDate::realToday();
        $filters = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'day' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $month = isset($filters['month']) ? CarbonImmutable::parse($filters['month'].'-01') : $today->startOfMonth();
        $end = $month->endOfMonth()->startOfDay();
        $day = isset($filters['day']) ? CarbonImmutable::parse($filters['day']) : ($today->isSameMonth($month) ? $today : $month);
        $cras = LeadGenerator::cras();

        $off = DutyGoals::offDays($cras, $month, $end);
        $logins = DutyLogin::whereDate('date', '>=', $month)->whereDate('date', '<=', $end)->whereIn('user_id', $cras->pluck('id'))->get()
            ->groupBy(fn (DutyLogin $login) => $login->date->toDateString());
        // Set ahead (or by a supervisor), to tell a planned day off from a missed log-in.
        $planned = DutySchedule::whereDate('date', '>=', $month)->whereDate('date', '<=', $end)->whereIn('user_id', $cras->pluck('id'))->get()
            ->groupBy(fn (DutySchedule $row) => $row->date->toDateString())
            ->map(fn ($rows) => $rows->pluck('type', 'user_id'));

        $totals = $cras->mapWithKeys(fn (User $cra) => [$cra->id => [
            'rest' => collect($off)->filter(fn (array $day) => ($day[$cra->id] ?? null) === DutySchedule::REST)->count(),
            'leave' => collect($off)->filter(fn (array $day) => ($day[$cra->id] ?? null) === DutySchedule::LEAVE)->count(),
            'duty' => $logins->filter(fn ($day) => $day->contains('user_id', $cra->id))->count(),
        ]]);

        $dayKey = $day->toDateString();
        $dayLogins = DutyLogin::whereDate('date', $day)->get()->keyBy('user_id');
        $dayPlanned = DutySchedule::whereDate('date', $day)->pluck('type', 'user_id');
        $dayOff = DutyGoals::offDays($cras, $day, $day)[$dayKey] ?? [];

        return view('cra-schedule.index', [
            'month' => $month,
            'today' => $today,
            'day' => $day,
            'cras' => $cras,
            'off' => $off,
            'logins' => $logins,
            'planned' => $planned,
            'totals' => $totals,
            'dayRows' => $cras->map(fn (User $cra) => [
                'cra' => $cra,
                'logged_in_at' => $dayLogins->get($cra->id)?->logged_in_at->timezone(config('segmentation.timezone'))->format('g:i A'),
                'planned' => $dayPlanned[$cra->id] ?? null,
                'off' => $dayOff[$cra->id] ?? null,
            ]),
            'canManage' => $request->user()->can('on_duty.manage'),
        ]);
    }

    /**
     * Set each CRA's day: Absent / Leave, Rest Day, or on duty (no day off). Logging in still counts as on duty.
     */
    public function update(Request $request): RedirectResponse
    {
        $cras = LeadGenerator::cras()->pluck('id')->map(fn ($id) => (string) $id)->all();
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'types' => ['required', 'array'],
            'types.*' => ['nullable', Rule::in(array_keys(DutySchedule::TYPES))],
        ]);
        $types = collect($data['types'])->only($cras);

        DutySchedule::whereDate('date', $data['date'])->whereIn('user_id', $types->filter(fn ($type) => $type === null)->keys())->delete();
        $set = $types->filter();
        if ($set->isNotEmpty()) {
            DutySchedule::upsert(
                $set->map(fn (string $type, $id) => ['user_id' => (int) $id, 'date' => $data['date'], 'type' => $type, 'created_at' => now(), 'updated_at' => now()])->values()->all(),
                ['user_id', 'date'],
                ['type', 'updated_at'],
            );
        }

        $date = CarbonImmutable::parse($data['date']);

        return redirect()->route('cra-schedule.index', ['month' => $date->format('Y-m'), 'day' => $date->toDateString()])
            ->with('status', 'Schedule for '.$date->format('D, M j').' saved.');
    }
}
