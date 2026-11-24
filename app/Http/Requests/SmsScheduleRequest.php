<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }

    /**
     * What to save on the CRA: her own schedule, or null to follow the default.
     *
     * @return array{sms_auto_send: bool, sms_schedule: array{send_time: string, windows: list<array{from: string, until: string}>, days: list<int>}|null}
     */
    public function schedule(): array
    {
        $data = $this->validated();

        return [
            'sms_auto_send' => $this->boolean('auto_send'),
            'sms_schedule' => $this->boolean('use_default') ? null : [
                'send_time' => $data['send_time'],
                'windows' => array_values(array_map(
                    fn (array $window) => ['from' => $window['from'], 'until' => $window['until']],
                    array_filter($data['windows'], fn (array $window) => filled($window['from'] ?? null)),
                )),
                'days' => collect($data['days'])->map(fn ($day) => (int) $day)->unique()->sort()->values()->all(),
            ],
        ];
    }
}
