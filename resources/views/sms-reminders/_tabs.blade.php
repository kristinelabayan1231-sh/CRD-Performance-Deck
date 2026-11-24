{{-- SMS Reminders sub pages; a page passes the counts it already has for the badges. --}}
<nav aria-label="SMS Reminders" class="flex flex-wrap gap-1.5">
    @foreach (array_filter([
        ["Today's texts", route('sms-reminders.index', array_filter(['cra' => request('cra')])), request()->routeIs('sms-reminders.index'), null],
        ['Conversations', route('sms-reminders.conversations.index', array_filter(['cra' => request('cra')])), request()->routeIs('sms-reminders.conversations.*'), $openReplies ?? null],
        ['Follow-ups', route('sms-reminders.follow-ups.index', array_filter(['cra' => request('cra')])), request()->routeIs('sms-reminders.follow-ups.*'), $followUpCount ?? null],
        ['No response', route('sms-reminders.no-response', array_filter(['cra' => request('cra')])), request()->routeIs('sms-reminders.no-response'), $noResponseCount ?? null],
        ['Templates', route('sms-reminders.templates.index', array_filter(['cra' => request('cra')])), request()->routeIs('sms-reminders.templates.*'), null],
        ['Schedule', route('sms-reminders.schedule.index', array_filter(['cra' => request('cra')])), request()->routeIs('sms-reminders.schedule.*'), null],
    ]) as [$label, $href, $active, $badge])
        <a href="{{ $href }}" @if ($active) aria-current="page" @endif
           @class(['inline-flex h-9 items-center gap-2 rounded-lg px-3.5 text-sm font-semibold', 'bg-ink text-white' => $active, 'border border-line bg-white text-ink hover:border-brand-400 hover:text-brand-600' => ! $active])>
            {{ $label }}
            @if ($badge)
                <span class="rounded-full bg-brand-600 px-1.5 text-xs text-white tabular-nums">{{ number_format($badge) }}</span>
            @endif
        </a>
    @endforeach
</nav>
