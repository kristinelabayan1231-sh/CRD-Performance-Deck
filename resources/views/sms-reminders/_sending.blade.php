{{-- The day's texts for a CRA (or all): counts, Send now and automatic sending. Today's texts.
     Needs: $counts, $sentToday, $isToday, $cra, $enabled, $phoneReady, $canViewAll (sms_reminders.view_all). --}}
<section aria-label="Sending" class="flex flex-wrap items-center justify-between gap-5 rounded-2xl border border-line bg-white p-5 shadow-sm">
    <div class="flex flex-wrap items-center gap-7">
        <div>
            <p class="text-4xl leading-none font-bold text-brand-700 tabular-nums">{{ number_format($counts['toSend']) }}</p>
            <p class="mt-1 text-sm font-semibold">customers to text{{ $counts['followUps'] ? ' · '.$counts['followUps'].' of them follow-ups' : '' }}</p>
        </div>
        <dl class="grid gap-1 text-sm text-muted">
            <div><dt class="inline">Sent:</dt> <dd class="inline font-semibold text-ink tabular-nums">{{ $counts['sent'] }}</dd>@if ($counts['queued']) · <span class="font-semibold text-[#1a4f8c]">{{ number_format($counts['queued']) }} queued</span>@endif</div>
            <div><dt class="inline">Left out by the CRA:</dt> <dd class="inline font-semibold text-ink tabular-nums">{{ $counts['excluded'] }}</dd></div>
            <div><dt class="inline">Left out by the app (reordered, opted out):</dt> <dd class="inline font-semibold text-ink tabular-nums">{{ $counts['skipped'] }}</dd>@if ($counts['failed']) · <span class="font-semibold text-coral-700">{{ $counts['failed'] }} failed</span>@endif</div>
        </dl>
    </div>

    @if ($isToday)
        @if ($cra)
            <div class="flex flex-col items-end gap-2">
                @php($canSend = $counts['toSend'] - $counts['queued'])
                <form method="POST" action="{{ route('sms-reminders.send-now') }}" class="flex flex-wrap items-center justify-end gap-3">
                    @csrf
                    <input type="hidden" name="cra" value="{{ $cra->id }}">
                    <label class="flex items-center gap-2 text-sm font-semibold">
                        How many
                        <input type="number" name="limit" min="1" max="{{ max(1, $canSend) }}" value="{{ $canSend ?: '' }}" required @disabled(! $canSend)
                               class="h-11 w-24 rounded-lg border border-line bg-white px-3 text-sm tabular-nums focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
                        <span class="font-normal text-muted">of {{ number_format($canSend) }}, top of the list first</span>
                    </label>
                    <button type="submit" @disabled(! $enabled || ! $canSend || ! $phoneReady)
                            class="inline-flex h-11 items-center gap-2 rounded-lg bg-brand-600 px-5 font-semibold text-white shadow-sm transition hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-50">
                        <svg class="size-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linejoin="round" d="M4 12 20 4l-6 16-2-6z"/></svg>
                        Send now
                    </button>
                </form>
                @if ($counts['queued'])
                    <form method="POST" action="{{ route('sms-reminders.stop') }}">
                        @csrf
                        <input type="hidden" name="cra" value="{{ $cra->id }}">
                        <button type="submit" class="h-9 rounded-lg border border-coral bg-coral/10 px-3 text-xs font-semibold text-coral-700 hover:bg-coral/20">Stop {{ number_format($counts['queued']) }} queued texts</button>
                    </form>
                @endif
                <form method="POST" action="{{ route('sms-reminders.auto-send') }}" class="flex items-center gap-2 text-sm">
                    @csrf @method('PUT')
                    <input type="hidden" name="cra" value="{{ $cra->id }}">
                    <input type="hidden" name="on" value="{{ $cra->sms_auto_send ? 0 : 1 }}">
                    <button type="submit" role="switch" aria-checked="{{ $cra->sms_auto_send ? 'true' : 'false' }}" aria-label="Send automatically every day"
                            @class(['relative h-6 w-11 shrink-0 rounded-full transition', 'bg-brand-600' => $cra->sms_auto_send, 'bg-line' => ! $cra->sms_auto_send])>
                        <span @class(['absolute top-0.5 size-5 rounded-full bg-white shadow transition-all', 'left-[1.375rem]' => $cra->sms_auto_send, 'left-0.5' => ! $cra->sms_auto_send])></span>
                    </button>
                    <span>{{ $cra->sms_auto_send ? 'Sends automatically: '.\App\Support\SmsSettings::scheduleLabel($cra) : 'Automatic sending off' }}</span>
                    <a href="{{ route('sms-reminders.schedule.index', $canViewAll ? ['cra' => $cra->id] : []) }}" class="font-semibold text-brand-600 hover:underline">Change schedule</a>
                </form>
                @php($smsSchedule = \App\Support\SmsSettings::scheduleFor($cra))
                @if (count($smsSchedule['all_windows']) > 1)
                    <div class="flex flex-wrap items-center justify-end gap-2 text-sm" role="group" aria-label="Sending windows">
                        @foreach ($smsSchedule['all_windows'] as $index => $window)
                            @php($windowOn = ! in_array($index, $smsSchedule['windows_off'], true))
                            <form method="POST" action="{{ route('sms-reminders.window') }}">
                                @csrf @method('PUT')
                                <input type="hidden" name="cra" value="{{ $cra->id }}">
                                <input type="hidden" name="window" value="{{ $index }}">
                                <input type="hidden" name="on" value="{{ $windowOn ? 0 : 1 }}">
                                <button type="submit" aria-pressed="{{ $windowOn ? 'true' : 'false' }}" title="{{ $windowOn ? 'Turn this window off: all the texts go out in the other one' : 'Turn this window on' }}"
                                        @class(['h-9 rounded-lg border px-3 text-xs font-semibold tabular-nums', 'border-brand-500 bg-brand-50 text-brand-700' => $windowOn, 'border-line bg-white text-muted line-through' => ! $windowOn])>{{ \App\Support\SmsSettings::windowsLabel([$window]) }} · {{ $windowOn ? 'On' : 'Off' }}</button>
                            </form>
                        @endforeach
                    </div>
                @endif
                @php($cap = \App\Support\SmsSettings::dailyCap())
                <form method="POST" action="{{ route('sms-reminders.daily-limit') }}" class="flex flex-wrap items-center justify-end gap-2 text-sm">
                    @csrf @method('PUT')
                    <input type="hidden" name="cra" value="{{ $cra->id }}">
                    <label class="flex items-center gap-2 font-semibold">
                        Daily limit
                        <input type="number" name="daily_limit" min="1" max="{{ $cap }}" value="{{ old('daily_limit', \App\Support\SmsSettings::ownDailyLimit($cra)) }}" placeholder="{{ $cap }}"
                               class="h-9 w-20 rounded-lg border border-line bg-white px-2.5 text-sm font-normal tabular-nums focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
                    </label>
                    <button type="submit" class="h-9 rounded-lg border border-line bg-white px-3 text-xs font-semibold hover:border-brand-400 hover:text-brand-600">Save</button>
                    <span class="text-muted tabular-nums">{{ $sentToday }} of {{ \App\Support\SmsSettings::dailyLimitFor($cra) }} sent today</span>
                </form>
                @if ($cra && ! \App\Services\SmsSender::linkFor($cra))
                    <p class="text-xs text-coral-700">{{ $cra->displayName() }}'s phone link isn't set in Settings → Call Recordings.</p>
                @elseif (! $phoneReady)
                    <p class="text-xs text-coral-700">{{ $cra->displayName() }}'s phone isn't ready for texts yet: mark it ready in Settings → SMS Reminders once it sends a test text. Their texts wait here until then.</p>
                @endif
            </div>
        @else
            <p class="max-w-xs text-sm text-muted">Pick a CRA to send their texts or turn on their automatic sending: texts go out from each CRA's own phone.</p>
        @endif
    @endif
</section>
