<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Support\SmsSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A CRA's schedule for automatic restock texts, from SMS Reminders → Schedule or Settings → SMS Reminders: on/off, and
 * either the default schedule or her own send time, sending windows and days. Her windows stay inside the hours texts may
 * go out at all (config sms_reminders.manual_hours).
 */
class SmsScheduleRequest extends FormRequest
{
    /**
     * Who may change whose schedule is checked by the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $hours = config('sms_reminders.manual_hours');
        $own = fn (array $rules) => $this->boolean('use_default') ? ['nullable'] : $rules;

        return [
            'auto_send' => ['sometimes', 'boolean'],
            'use_default' => ['sometimes', 'boolean'],
            'send_time' => $own(['required', 'date_format:H:i']),
            'windows.0.from' => $own(['required', 'date_format:H:i', 'after_or_equal:'.$hours['from']]),
            'windows.0.until' => $own(['required', 'date_format:H:i', 'after:windows.0.from', 'before_or_equal:'.$hours['until']]),
            'windows.1.from' => $own(['nullable', 'required_with:windows.1.until', 'date_format:H:i', 'after_or_equal:windows.0.until']),
            'windows.1.until' => $own(['nullable', 'required_with:windows.1.from', 'date_format:H:i', 'after:windows.1.from', 'before_or_equal:'.$hours['until']]),
            'days' => $own(['required', 'array', 'min:1']),
            'days.*' => ['integer', 'between:1,7'],
            'windows_on' => ['sometimes', 'array'],
            'windows_on.*' => ['nullable', 'integer', 'between:0,1'],
            'daily_limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.SmsSettings::dailyCap()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $hours = collect(config('sms_reminders.manual_hours'))->map(fn (string $time) => date('g:i A', strtotime($time)));

        return [
            'windows.0.from.after_or_equal' => "Texts can go out from {$hours['from']}.",
            'windows.0.until.before_or_equal' => "Texts can go out until {$hours['until']}.",
            'windows.1.until.before_or_equal' => "Texts can go out until {$hours['until']}.",
            'windows.0.until.after' => 'The first window has to end after it starts.',
            'windows.1.until.after' => 'The second window has to end after it starts.',
            'windows.1.from.after_or_equal' => 'The second window has to start after the first one ends.',
            'days.required' => 'Pick at least one day.',
            'days.min' => 'Pick at least one day.',
            'daily_limit.max' => 'The most texts a phone can send a day is '.SmsSettings::dailyCap().' (Settings → SMS Reminders).',
        ];
    }

    /**
     * What to save on $cra: her own schedule and daily limit, or null to follow the default for both.
     *
     * @return array{sms_auto_send: bool, sms_schedule: array{send_time?: string, windows?: list<array{from: string, until: string}>, days?: list<int>, daily_limit?: int}|null}
     */
    public function schedule(User $cra): array
    {
        $data = $this->validated();
        $limit = array_key_exists('daily_limit', $data) ? $data['daily_limit'] : SmsSettings::ownDailyLimit($cra);

        return [
            'sms_auto_send' => $this->boolean('auto_send'),
            'sms_schedule' => SmsSettings::withWindowsOff(SmsSettings::withDailyLimit($this->boolean('use_default') ? null : [
                'send_time' => $data['send_time'],
                'windows' => array_values(array_map(
                    fn (array $window) => ['from' => $window['from'], 'until' => $window['until']],
                    array_filter($data['windows'], fn (array $window) => filled($window['from'] ?? null)),
                )),
                'days' => collect($data['days'])->map(fn ($day) => (int) $day)->unique()->sort()->values()->all(),
            ], $limit === null ? null : (int) $limit), $this->windowsOff($cra)),
        ];
    }

    /**
     * The windows unticked on the form; kept as they were when the form doesn't send them.
     *
     * @return list<int>
     */
    private function windowsOff(User $cra): array
    {
        if (! $this->has('windows_on')) {
            return array_map('intval', (array) ($cra->sms_schedule['windows_off'] ?? []));
        }

        return array_values(array_diff(range(0, max(0, $this->windowCount() - 1)), $this->windowsOn()));
    }

    /**
     * @return list<int>
     */
    private function windowsOn(): array
    {
        return array_map('intval', array_filter((array) $this->input('windows_on'), 'is_numeric'));
    }

    /**
     * How many windows the schedule being saved has.
     */
    private function windowCount(): int
    {
        return $this->boolean('use_default')
            ? count(SmsSettings::windows())
            : count(array_filter($this->input('windows', []), fn (array $window) => filled($window['from'] ?? null)));
    }

    /**
     * At least one window stays on.
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if (! $this->has('windows_on') || $validator->errors()->isNotEmpty()) {
                return;
            }

            if (array_intersect($this->windowsOn(), range(0, max(0, $this->windowCount() - 1))) === []) {
                $validator->errors()->add('windows_on', 'Keep at least one window on, or turn off Send automatically.');
            }
        }];
    }
}
