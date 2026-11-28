{{-- The tracker's sending bar: the CRA's texts today (SmsSendBar::for), the same controls as Today's texts' sending box.
     Needs $sendBar. --}}
@php
    ['cra' => $barCra, 'counts' => $barCounts] = $sendBar;
    $canSend = $barCounts['toSend'] - $barCounts['queued'];
    $chip = 'flex h-10 items-center gap-1.5 rounded-full border border-[#d9d9d6] bg-white px-3.5 text-[0.8125rem] font-medium whitespace-nowrap tabular-nums [&_strong]:font-display [&_strong]:text-[0.9375rem] hover:border-ink';
    $tracker = request()->routeIs('exclusive-leads.*') ? 'exclusive-leads.index' : 'segmentation.index';
    $viewAll = auth()->user()->can('sms_reminders.view_all');
    $schedule = \App\Support\SmsSettings::scheduleFor($barCra);
    $cap = \App\Support\SmsSettings::dailyCap();
@endphp
<section aria-label="Sending" class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-line bg-white px-3 py-2.5">
    {{-- Today's texts at a glance: each opens Today's texts on that list --}}
    <div class="flex flex-wrap items-center gap-1.5">
        <a href="{{ route('sms-reminders.index', array_filter(['status' => 'sent', 'cra' => $viewAll ? $barCra->id : null])) }}" class="{{ $chip }}" title="Texts sent today">
            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7"/></svg>
            Sent <strong>{{ number_format($barCounts['sent']) }}</strong>
        </a>
        <a href="{{ route('sms-reminders.conversations.index', array_filter(['cra' => $viewAll ? $barCra->id : null])) }}" class="{{ $chip }}" title="Replied YES and still to call">
            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7 11v9H4v-9h3Zm0 0 4-7a2 2 0 0 1 2 2v4h5a2 2 0 0 1 2 2.3l-1.2 6A2 2 0 0 1 16.8 20H7"/></svg>
            Replied YES <strong>{{ number_format($sendBar['wantsToReorder']) }}</strong>
        </a>
        <a href="{{ route('sms-reminders.index', array_filter(['status' => 'out', 'cra' => $viewAll ? $barCra->id : null])) }}" class="{{ $chip }}" title="Left out of today's texts (they stay on the call list)">
            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M8 12h8"/></svg>
            Unticked <strong>{{ number_format($barCounts['excluded']) }}</strong>
        </a>
        @if ($barCounts['failed'])
            <a href="{{ route('sms-reminders.index', array_filter(['status' => 'failed', 'cra' => $viewAll ? $barCra->id : null])) }}" class="{{ $chip }} text-coral-700" title="Texts that didn't go out">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>
                Failed <strong>{{ number_format($barCounts['failed']) }}</strong>
            </a>
        @endif
    </div>

    <div class="flex flex-wrap items-center justify-end gap-2">
        {{-- Sending windows: on = black --}}
        @if (count($schedule['all_windows']) > 1)
            @foreach ($schedule['all_windows'] as $index => $window)
                @php $windowOn = ! in_array($index, $schedule['windows_off'], true); @endphp
                <form method="POST" action="{{ route('sms-reminders.window') }}">
                    @csrf @method('PUT')
                    <input type="hidden" name="cra" value="{{ $barCra->id }}">
                    <input type="hidden" name="window" value="{{ $index }}">
                    <input type="hidden" name="on" value="{{ $windowOn ? 0 : 1 }}">
                    <button type="submit" aria-pressed="{{ $windowOn ? 'true' : 'false' }}" title="{{ $windowOn ? 'On: turn this window off, all the texts go out in the other one' : 'Off: turn this window on' }}"
                            @class(['flex h-9 items-center gap-1.5 rounded-full px-3 text-xs font-semibold whitespace-nowrap tabular-nums', 'bg-brand-600 text-white' => $windowOn, 'border border-line bg-white text-muted line-through' => ! $windowOn])>
                        <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 7v5l3 2"/></svg>
                        {{ \App\Support\SmsSettings::windowsLabel([$window]) }}
                    </button>
                </form>
            @endforeach
        @endif

        {{-- Automatic sending --}}
        <form method="POST" action="{{ route('sms-reminders.auto-send') }}" class="flex items-center">
            @csrf @method('PUT')
            <input type="hidden" name="cra" value="{{ $barCra->id }}">
            <input type="hidden" name="on" value="{{ $barCra->sms_auto_send ? 0 : 1 }}">
            <button type="submit" role="switch" aria-checked="{{ $barCra->sms_auto_send ? 'true' : 'false' }}"
                    aria-label="Send automatically every day" title="{{ $barCra->sms_auto_send ? 'Sends automatically: '.\App\Support\SmsSettings::scheduleLabel($barCra) : 'Automatic sending off' }}"
                    @class(['relative h-7 w-12 shrink-0 rounded-full transition', 'bg-brand-600' => $barCra->sms_auto_send, 'bg-line' => ! $barCra->sms_auto_send])>
                <span @class(['absolute top-0.5 size-6 rounded-full bg-white shadow transition-all', 'left-[1.375rem]' => $barCra->sms_auto_send, 'left-0.5' => ! $barCra->sms_auto_send])></span>
            </button>
        </form>
        <a href="{{ route('sms-reminders.schedule.index', $viewAll ? ['cra' => $barCra->id] : []) }}" title="Change the schedule" aria-label="Change the texting schedule"
           class="grid size-9 place-items-center rounded-full border border-line bg-white text-muted hover:text-ink">
            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path stroke-linecap="round" d="M12 3v2M12 19v2M3 12h2M19 12h2M5.6 5.6l1.4 1.4M17 17l1.4 1.4M5.6 18.4 7 17M17 7l1.4-1.4"/></svg>
        </a>

        {{-- Daily limit --}}
        <form method="POST" action="{{ route('sms-reminders.daily-limit') }}" class="flex items-center gap-1.5 text-xs">
            @csrf @method('PUT')
            <input type="hidden" name="cra" value="{{ $barCra->id }}">
            <label class="flex items-center gap-1.5 font-semibold text-ink" title="Most texts this phone sends a day, up to {{ $cap }}">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 14l4-4M3.3 17a9 9 0 1 1 17.4 0"/></svg>
                Limit
                <input type="number" name="daily_limit" min="1" max="{{ $cap }}" value="{{ \App\Support\SmsSettings::ownDailyLimit($barCra) }}" placeholder="{{ $cap }}" onchange="this.form.submit()"
                       class="h-10 w-[4.5rem] rounded-[0.625rem] border border-line bg-white px-2 text-sm font-semibold text-ink tabular-nums focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
            </label>
            <span class="text-muted tabular-nums" title="Sent from {{ $barCra->displayName() }}'s phone today">{{ number_format($sendBar['sentToday']) }} sent</span>
        </form>

        @if ($barCounts['queued'])
            <form method="POST" action="{{ route('sms-reminders.stop') }}">
                @csrf
                <input type="hidden" name="cra" value="{{ $barCra->id }}">
                <button type="submit" class="flex h-10 items-center gap-1.5 rounded-full border border-coral/50 bg-coral/10 px-3.5 text-xs font-semibold text-coral-700 hover:bg-coral/20">Stop {{ number_format($barCounts['queued']) }} queued</button>
            </form>
        @endif

        {{-- Send now: how many, top of the list first --}}
        <form method="POST" action="{{ route('sms-reminders.send-now') }}" class="flex items-center">
            @csrf
            <input type="hidden" name="cra" value="{{ $barCra->id }}">
            <label class="sr-only" for="send-bar-limit">How many to send</label>
            <input id="send-bar-limit" type="number" name="limit" min="1" max="{{ max(1, $canSend) }}" value="{{ $canSend ?: '' }}" required @disabled(! $canSend)
                   oninput="this.form.querySelector('[data-send-n]').textContent = Math.min(this.value || 0, {{ $canSend }})"
                   class="h-10 w-16 rounded-l-full border border-r-0 border-line pl-3.5 bg-white px-2.5 text-sm font-semibold tabular-nums focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
            <button type="submit" @disabled(! $sendBar['enabled'] || ! $canSend || ! $sendBar['phoneReady'])
                    class="inline-flex h-10 items-center gap-2 rounded-r-full bg-brand-600 pr-5 pl-4 text-sm font-semibold text-white transition hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-50"
                    title="Texts from {{ $barCra->displayName() }}'s phone, top of the list first">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linejoin="round" d="M4 12 20 4l-6 16-2-6z"/></svg>
                Send <span data-send-n>{{ number_format($canSend) }}</span> of {{ number_format($canSend) }}
            </button>
        </form>
    </div>

    @if (! $sendBar['enabled'])
        <p class="basis-full text-xs text-muted">SMS Reminders is off: the list is built every morning, but no texts go out.</p>
    @elseif (! $sendBar['linked'])
        <p class="basis-full text-xs text-coral-700">{{ $barCra->displayName() }}'s phone link isn't set in Settings → Call Recordings.</p>
    @elseif (! $sendBar['phoneReady'])
        <p class="basis-full text-xs text-coral-700">{{ $barCra->displayName() }}'s phone isn't ready for texts yet: mark it ready in Settings → SMS Reminders once it sends a test text.</p>
    @endif
</section>
