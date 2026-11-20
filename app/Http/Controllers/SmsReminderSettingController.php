<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use App\Services\LeadGenerator;
use App\Services\SmsSender;
use App\Support\SmsSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Settings → SMS Reminders: on/off, the daily automatic send time, the daily cap per phone, each CRA phone's
 * readiness, and a test text through a CRA's phone.
 */
class SmsReminderSettingController extends Controller
{
    public function index(): View
    {
        return view('settings.sms-reminders', [
            'enabled' => SmsSettings::enabled(),
            'sendTime' => SmsSettings::sendTime(),
            'dailyCap' => SmsSettings::dailyCap(),
            'cras' => LeadGenerator::cras()->map(fn (User $cra) => ['cra' => $cra, 'link' => SmsSender::linkFor($cra), 'hasToken' => (bool) $cra->recording_token]),
            'replyUrl' => route('api.sms-replies.store'),
            'hours' => config('sms_reminders.sending_hours'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'send_time' => ['required', 'date_format:H:i'],
            'daily_cap' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);

        Setting::put(SmsSettings::ENABLED, $request->boolean('enabled') ? '1' : '0', $request->user());
        Setting::put(SmsSettings::SEND_TIME, $data['send_time'], $request->user());
        Setting::put(SmsSettings::DAILY_CAP, (string) $data['daily_cap'], $request->user());

        return back()->with('status', 'SMS Reminders saved.');
    }

    /**
     * One test text from a CRA's phone to the number typed in, to check the "Send reminder" macro.
     */
    public function test(Request $request, SmsSender $sender): RedirectResponse
    {
        $request->merge(['number' => preg_replace('/[\s\-()]/', '', (string) $request->input('number'))]);
        $data = $request->validate([
            'cra' => ['required', 'integer', Rule::in(LeadGenerator::cras()->pluck('id')->all())],
            'number' => ['required', 'string', 'regex:/^(\+?63|0)?9\d{9}$/'],
        ], ['number.regex' => 'Type a PH mobile number, e.g. 0917 123 4567.']);
        $cra = User::findOrFail($data['cra']);

        $result = $sender->send($cra, $data['number'], "Test text from the CRD Performance Deck via {$cra->displayName()}'s phone. Reply YES to check replies come back.");

        return $result === SmsSender::SENT
            ? back()->with('status', "Test text sent from {$cra->displayName()}'s phone. Check it arrived, then reply YES and look for it in SMS Reminders → Replies.")
            : back()->withErrors(['test' => SmsSender::explain($result)]);
    }
}
