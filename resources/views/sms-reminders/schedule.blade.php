@php
    use App\Support\SmsSettings;

    $control = 'h-10 rounded-lg border border-line bg-white px-3 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none';
@endphp

<x-layouts.app title="SMS Schedule">
    <div class="space-y-4">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">SMS Reminders <span class="text-sm font-normal text-muted">· Schedule</span></h1>
                <p class="text-sm text-muted">{{ $isOwn ? 'When your' : ($cra ? 'When '.$cra->displayName()."'s" : 'When a CRA\'s') }} restock texts and follow-ups go out by themselves. Send now still sends right away, inside the sending windows.</p>
            </div>
            @include('sms-reminders._tabs')
        </header>

        @if ($errors->any())
            <div role="alert" class="rounded-lg border border-coral/60 bg-coral/10 px-4 py-3 text-sm text-coral-700">{{ $errors->first() }}</div>
        @endif
        @if (session('status'))
            <div role="status" class="rounded-lg border border-teal/50 bg-teal/10 px-4 py-3 text-sm text-teal-700">{{ session('status') }}</div>
        @endif
        @unless ($enabled)
            <div role="status" class="rounded-lg border border-line bg-white px-4 py-3 text-sm">
                SMS Reminders is <span class="font-semibold">off</span>: no texts go out on any schedule.
                @can('sms_reminders.manage') <a href="{{ route('settings.sms-reminders.index') }}" class="font-semibold text-brand-600 hover:underline">Turn it on in Settings → SMS Reminders</a>. @endcan
            </div>
        @endunless

        @if ($cra)
            @if ($cras->isNotEmpty())
                <form method="GET" action="{{ route('sms-reminders.schedule.index') }}" class="flex flex-col gap-1.5 text-sm font-semibold">
                    <label for="schedule-cra">CRA</label>
                    <select id="schedule-cra" name="cra" onchange="this.form.submit()" class="{{ $control }} w-56 font-normal">
                        @foreach ($cras as $option)
                            <option value="{{ $option->id }}" @selected($cra->is($option))>{{ $option->displayName() }}</option>
                        @endforeach
                    </select>
                    <noscript><button type="submit" class="h-10 rounded-lg border border-line px-3 text-sm">Show</button></noscript>
                </form>
            @endif

            <section aria-label="Automatic texts" class="space-y-4 rounded-xl border border-line bg-white p-5 shadow-sm">
                <div>
                    <h2 class="text-base font-semibold">Automatic texts</h2>
                    <p class="text-sm text-muted">
                        Now:
                        <span class="font-semibold text-ink">{{ $cra->sms_auto_send ? SmsSettings::scheduleLabel($cra) : 'Off' }}</span>
                        @if ($cra->sms_auto_send)
                            <span>({{ $schedule['custom'] ? 'own schedule' : 'default schedule' }})</span>
                        @endif
                    </p>
                </div>
                @include('sms-reminders._schedule-form', [
                    'action' => route('sms-reminders.schedule.update', $isOwn ? [] : ['cra' => $cra->id]),
                    'cra' => $cra,
                    'schedule' => $schedule,
                    'default' => $default,
                    'isOwn' => $isOwn,
                ])
            </section>
        @else
            <p class="rounded-xl border border-line bg-white p-5 text-sm text-muted shadow-sm">No CRA yet.</p>
        @endif
    </div>
</x-layouts.app>
