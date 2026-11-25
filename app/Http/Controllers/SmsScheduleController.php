<?php

namespace App\Http\Controllers;

use App\Http\Requests\SmsScheduleRequest;
use App\Models\User;
use App\Services\LeadGenerator;
use App\Support\SmsSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * SMS Reminders → Schedule: a CRA's own schedule for automatic restock texts (on/off, send time, sending windows, days),
 * or the default one from Settings → SMS Reminders. A CRA sees and changes only hers; someone who can see every CRA's
 * texts picks the CRA.
 */
class SmsScheduleController extends Controller
{
    public function index(Request $request): View
    {
        $cra = $this->cra($request);

        return view('sms-reminders.schedule', [
            'cra' => $cra,
            'isOwn' => $cra?->is($request->user()) ?? false,
            'cras' => $request->user()->can('sms_reminders.view_all') ? LeadGenerator::cras() : collect(),
            'schedule' => SmsSettings::scheduleFor($cra),
            'default' => SmsSettings::scheduleFor(null),
            'enabled' => SmsSettings::enabled(),
        ]);
    }

    public function update(SmsScheduleRequest $request): RedirectResponse
    {
        $cra = $this->cra($request);
        abort_unless($cra, 404);
        $cra->forceFill($request->schedule($cra))->save();

        return redirect()->route('sms-reminders.schedule.index', $cra->is($request->user()) ? [] : ['cra' => $cra->id])
            ->with('status', self::savedMessage($cra));
    }

    /**
     * "Automatic texts on: Every day · from 9:30 AM · …" for the flash after a save (also used by Settings).
     */
    public static function savedMessage(User $cra): string
    {
        return $cra->sms_auto_send
            ? "{$cra->displayName()}'s automatic texts: ".SmsSettings::scheduleLabel($cra).'.'
            : "Automatic sending off for {$cra->displayName()}: texts go out only with Send now.";
    }

    /**
     * Whose schedule: your own; someone who can see every CRA's texts picks the CRA (?cra=), else the first CRA when
     * they aren't one themselves.
     */
    private function cra(Request $request): ?User
    {
        $user = $request->user();

        if (! $user->can('sms_reminders.view_all')) {
            return $user;
        }

        $cras = LeadGenerator::cras();

        return $cras->firstWhere('id', (int) $request->input('cra')) ?? ($cras->contains('id', $user->id) ? $user : $cras->first());
    }
}
