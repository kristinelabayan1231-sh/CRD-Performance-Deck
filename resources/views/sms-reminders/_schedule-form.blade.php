{{--
    A CRA's schedule for automatic restock texts: on/off, then the default schedule or her own send time, windows and
    days. Used by SMS Reminders → Schedule and Settings → SMS Reminders.
    Needs: $action (PUT url), $cra, $schedule and $default (SmsSettings::scheduleFor), optional $isOwn.
--}}
@php
    use App\Support\SmsSettings;

    $field = 'h-10 rounded-lg border border-line bg-white px-3 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none';
    $hours = config('sms_reminders.manual_hours');
    $dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
    // After a failed save the form shows what was typed, on the right form only.
    $isThisForm = (int) old('schedule_for') === $cra->id;
    $useDefault = $isThisForm ? (bool) old('use_default') : ! $schedule['custom'];
    $autoSend = $isThisForm ? (bool) old('auto_send') : $cra->sms_auto_send;
    $days = $isThisForm ? array_map('intval', (array) old('days', [])) : $schedule['days'];
    $windows = $isThisForm ? old('windows', []) : $schedule['windows'];
    $sendTime = $isThisForm ? old('send_time') : $schedule['send_time'];
    $you = $isOwn ?? false;
@endphp

<form method="POST" action="{{ $action }}" class="group space-y-4">
    @csrf @method('PUT')
    <input type="hidden" name="schedule_for" value="{{ $cra->id }}">

    <label class="flex items-center gap-2.5 text-sm font-semibold">
        <input type="hidden" name="auto_send" value="0">
        <input type="checkbox" name="auto_send" value="1" @checked($autoSend) class="size-4.5 rounded accent-brand-600">
        Send automatically
        <span class="font-normal text-muted">({{ $you ? 'off: your' : 'off: '.$cra->displayName()."'s" }} texts go out only with Send now)</span>
    </label>

    <fieldset class="space-y-2">
        <legend class="sr-only">Which schedule</legend>
        <label class="flex items-start gap-2.5 text-sm">
            <input type="radio" name="use_default" value="1" @checked($useDefault) class="use-default mt-0.5 size-4.5 accent-brand-600">
            <span><span class="font-semibold">Use the default schedule</span> <span class="text-muted">· {{ SmsSettings::daysLabel($default['days']) }} · from {{ SmsSettings::timeLabel($default['send_time']) }} · {{ SmsSettings::windowsLabel($default['windows']) }}</span></span>
        </label>
        <label class="flex items-start gap-2.5 text-sm">
            <input type="radio" name="use_default" value="0" @checked(! $useDefault) class="mt-0.5 size-4.5 accent-brand-600">
            <span class="font-semibold">{{ $you ? 'My own schedule' : 'Own schedule' }}</span>
        </label>
    </fieldset>

    <div class="space-y-4 rounded-lg border border-line bg-canvas/50 p-4 group-has-[.use-default:checked]:hidden">
        <fieldset>
            <legend class="mb-1.5 text-sm font-semibold">Days</legend>
            <div class="flex flex-wrap gap-1.5">
                @foreach ($dayNames as $number => $name)
                    <label class="cursor-pointer">
                        <input type="checkbox" name="days[]" value="{{ $number }}" @checked(in_array($number, $days, true)) class="peer sr-only">
                        <span class="grid h-9 w-12 place-items-center rounded-lg border border-line bg-white text-sm font-semibold text-muted peer-checked:border-brand-600 peer-checked:bg-brand-600 peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-brand-200">{{ $name }}</span>
                    </label>
                @endforeach
            </div>
        </fieldset>

        <div class="flex flex-wrap items-end gap-4">
            <label class="flex flex-col gap-1.5 text-sm font-semibold">
                Start sending at
                <input type="time" name="send_time" value="{{ $sendTime }}" class="{{ $field }} font-normal">
            </label>
            @foreach (['First window', 'Second window (optional)'] as $index => $name)
                @php($window = $windows[$index] ?? ['from' => '', 'until' => ''])
                <div class="flex flex-col gap-1.5">
                    <span class="text-sm font-semibold">{{ $name }}</span>
                    <div class="flex items-center gap-1.5">
                        <input type="time" name="windows[{{ $index }}][from]" value="{{ $window['from'] ?? '' }}" aria-label="{{ $name }} from" class="{{ $field }}">
                        <span class="text-sm text-muted">to</span>
                        <input type="time" name="windows[{{ $index }}][until]" value="{{ $window['until'] ?? '' }}" aria-label="{{ $name }} until" class="{{ $field }}">
                    </div>
                </div>
            @endforeach
        </div>
        <p class="text-xs text-muted">On the days picked, the ticked customers are queued from the start time and go out one a minute, only inside the windows ({{ SmsSettings::timeLabel($hours['from']) }}–{{ SmsSettings::timeLabel($hours['until']) }}); what's left after the last window waits for the next sending day. Leave the second window blank for one window only.</p>
    </div>

    <button type="submit" class="h-10 rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white hover:bg-brand-700">Save schedule</button>
</form>
