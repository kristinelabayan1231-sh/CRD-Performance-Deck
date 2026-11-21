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
            'windows' => SmsSettings::windows(),
            'windowsLabel' => SmsSettings::windowsLabel(),
        ]);
    }

    /**
     * The second sending window is optional (both times blank = one window); each ends after it starts, and the second
     * starts after the first ends.
     */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'send_time' => ['required', 'date_format:H:i'],
            'daily_cap' => ['required', 'integer', 'min:1', 'max:1000'],
            'windows.0.from' => ['required', 'date_format:H:i'],
            'windows.0.until' => ['required', 'date_format:H:i', 'after:windows.0.from'],
            'windows.1.from' => ['nullable', 'required_with:windows.1.until', 'date_format:H:i', 'after_or_equal:windows.0.until'],
            'windows.1.until' => ['nullable', 'required_with:windows.1.from', 'date_format:H:i', 'after:windows.1.from'],
        ], [
            'windows.0.until.after' => 'The morning window has to end after it starts.',
            'windows.1.until.after' => 'The afternoon window has to end after it starts.',
            'windows.1.from.after_or_equal' => 'The afternoon window has to start after the morning one ends.',
        ]);
        $windows = array_values(array_filter($data['windows'], fn (array $window) => filled($window['from'] ?? null)));

        Setting::put(SmsSettings::ENABLED, $request->boolean('enabled') ? '1' : '0', $request->user());
        Setting::put(SmsSettings::SEND_TIME, $data['send_time'], $request->user());
        Setting::put(SmsSettings::DAILY_CAP, (string) $data['daily_cap'], $request->user());
        Setting::put(SmsSettings::WINDOWS, json_encode($windows), $request->user());

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
