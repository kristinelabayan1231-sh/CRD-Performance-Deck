{{-- SMS Reminders sub pages. --}}
<nav aria-label="SMS Reminders" class="flex flex-wrap gap-1.5">
    @foreach (array_filter([
        ['Customers', route('sms-reminders.index', array_filter(['cra' => request('cra')])), request()->routeIs('sms-reminders.index'), null],
        ['Replies', route('sms-reminders.replies.index', array_filter(['cra' => request('cra')])), request()->routeIs('sms-reminders.replies.*'), $openReplies ?? null],
        auth()->user()->can('sms_reminders.manage') ? ['Templates', route('sms-reminders.templates.index'), request()->routeIs('sms-reminders.templates.*'), null] : null,
    ]) as [$label, $href, $active, $badge])
        <a href="{{ $href }}" @if ($active) aria-current="page" @endif
           @class(['inline-flex h-9 items-center gap-2 rounded-lg px-3.5 text-sm font-semibold', 'bg-ink text-white' => $active, 'border border-line bg-white text-ink hover:border-brand-400 hover:text-brand-600' => ! $active])>
            {{ $label }}
            @if ($badge)
                <span class="rounded-full bg-brand-600 px-1.5 text-xs text-white tabular-nums">{{ $badge }}</span>
            @endif
        </a>
    @endforeach
</nav>
