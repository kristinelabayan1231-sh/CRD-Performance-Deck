{{-- The tracker's tabs, one row of icons: Daily and Weekly (Leads and goals with Day / Month on the Exclusive Lead Tracker),
     Conversations (replies, follow-ups, no response), Today's texts, and the text settings' Templates and Schedule.
     On the Exclusive Lead Tracker's page pass $exclusiveCra (its CRA) and $readOnly. --}}
@php
    $tabUser = auth()->user();
    $exclusiveCra ??= null;
    $onSms = request()->routeIs('sms-reminders.*');
    $pickedCra = $onSms && ctype_digit((string) request('cra')) && $tabUser->can('sms_reminders.view_all') ? \App\Models\User::find((int) request('cra')) : null;
    $exclusiveOf = $exclusiveCra ?? ($pickedCra?->isExclusiveOn(\App\Models\Lead::today()) ? $pickedCra
        : ($tabUser->isExclusiveOn(\App\Models\Lead::today()) && ! request()->routeIs('segmentation.index') ? $tabUser : null));
    $exclusive = $exclusiveOf && $tabUser->can('exclusive_leads.view');
    $smsCra = array_filter(['cra' => $exclusiveCra && ! $exclusiveCra->is($tabUser) ? $exclusiveCra->id : ($onSms ? request('cra') : null)]);
    $canSms = $tabUser->can('sms_reminders.view') && ! ($readOnly ?? false) && (! $exclusiveCra || $exclusiveCra->is($tabUser) || $tabUser->can('sms_reminders.view_all'));
    $onConversations = request()->routeIs('sms-reminders.conversations.*', 'sms-reminders.follow-ups.*', 'sms-reminders.no-response');
    $onSettings = request()->routeIs('sms-reminders.templates.*', 'sms-reminders.schedule.*');
    $onLeads = ! $onSms;

    $leadsUrl = match (true) {
        $exclusive => route('exclusive-leads.index', $exclusiveOf->is($tabUser) ? [] : ['cra' => $exclusiveOf->id]),
        $tabUser->can('segmentation.view') => route('segmentation.index'),
        default => null,
    };
    $tabs = $leadsUrl ? [[$exclusive ? 'Leads and goals' : 'Leads', $leadsUrl, $onLeads, null]] : [];
    if ($canSms) {
        $toAnswer = $openReplies ?? \App\Models\SmsReply::needsAnswer()
            ->when(! $tabUser->can('sms_reminders.view_all') ? $tabUser->id : ($smsCra['cra'] ?? null), fn ($query, $id) => $query->where('user_id', $id))
            ->distinct()->count('phone_key');
        $tabs = [...$tabs,
            ['Conversations', route('sms-reminders.conversations.index', $smsCra), $onConversations, $toAnswer],
            ["Today's texts", route('sms-reminders.index', $smsCra), request()->routeIs('sms-reminders.index'), null],
            ['Text settings', route('sms-reminders.templates.index', $smsCra), $onSettings, null],
        ];
    }

    $views = match (true) {
        $onSettings => [['Templates', route('sms-reminders.templates.index', $smsCra), request()->routeIs('sms-reminders.templates.*')], ['Schedule', route('sms-reminders.schedule.index', $smsCra), request()->routeIs('sms-reminders.schedule.*')]],
        $onLeads && $tabUser->can('segmentation.view') && ! $exclusiveCra => [['Daily', $leadsUrl, ! request()->routeIs('segmentation.weekly')], ['Weekly Segmentation', route('segmentation.weekly'), request()->routeIs('segmentation.weekly')]],
        default => [],
    };
@endphp
@php
    // One row of icon tabs: the open one shows its label, the others their icon (label on hover and for screen readers).
    $tabIcons = [
        'Leads' => 'M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01',
        'Leads and goals' => 'M12 3l2.7 5.6 6.1.8-4.5 4.2 1.1 6.1L12 16.8l-5.4 2.9 1.1-6.1-4.5-4.2 6.1-.8z',
        'Daily' => 'M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01',
        'Weekly Segmentation' => 'M4 6h16v14H4zM4 10h16M8 3v4M16 3v4',
        'Conversations' => 'M4 5h16v11H9l-5 4z',
        "Today's texts" => 'M4 12 20 4l-6 16-2-6z',
        'Text settings' => 'M4 7h10M18 7h2M4 17h2M10 17h10M14 5v4M8 15v4',
        'Templates' => 'M6 3h9l4 4v14H6zM14 3v5h5M9 13h6M9 17h6',
        'Schedule' => 'M12 7v5l3 2M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18',
    ];
    // The Leads tab splits into its views (Daily / Weekly) and Text settings into Templates / Schedule, all in one row.
    $flat = [];
    foreach ($tabs as [$label, $href, $active, $badge]) {
        $split = match (true) {
            $label === 'Leads' && $active && $views => $views,
            $label === 'Text settings' && $active && $views => $views,
            $label === 'Leads' && ! $exclusive && $tabUser->can('segmentation.view') => [['Daily', $href, false], ['Weekly Segmentation', route('segmentation.weekly'), false]],
            $label === 'Text settings' => [['Templates', route('sms-reminders.templates.index', $smsCra), false], ['Schedule', route('sms-reminders.schedule.index', $smsCra), false]],
            default => null,
        };
        if ($split) {
            foreach ($split as [$viewLabel, $viewHref, $viewActive]) {
                $flat[] = [$viewLabel, $viewHref, $viewActive, null];
            }
        } else {
            $flat[] = [$label, $href, $active, $badge];
        }
    }
@endphp
<div class="mb-3 flex shrink-0 flex-wrap items-center gap-2">
    <nav class="flex flex-wrap items-center gap-1" aria-label="Tracker sections">
        @foreach ($flat as [$label, $href, $active, $badge])
            <a href="{{ $href }}" @if ($active) aria-current="page" @endif
               @class([
                   'group flex h-10 shrink-0 items-center rounded-full text-sm font-semibold transition-colors',
                   'border border-line bg-white px-4 text-ink shadow-sm' => $active,
                   'px-3 text-muted hover:bg-white hover:text-ink' => ! $active,
               ])>
                <svg @class(['size-[1.15rem]', 'text-[#c99400]' => $active]) fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $tabIcons[$label] ?? $tabIcons['Leads'] }}"/></svg>
                {{-- The label slides open on hover or focus (always open on the current tab), as in the mockup --}}
                <span @class([
                    'overflow-hidden whitespace-nowrap transition-[max-width,opacity,margin] duration-300 ease-out',
                    'ml-2 max-w-[12rem] opacity-100' => $active,
                    'ml-0 max-w-0 opacity-0 group-hover:ml-2 group-hover:max-w-[12rem] group-hover:opacity-100 group-focus-visible:ml-2 group-focus-visible:max-w-[12rem] group-focus-visible:opacity-100' => ! $active,
                ])>{{ $label }}</span>
                @if ($badge)
                    <span title="Replies to answer" class="ml-2 grid min-w-5 place-items-center rounded-full bg-brand-500 px-1.5 text-xs font-bold text-ink tabular-nums">{{ number_format($badge) }}</span>
                @endif
            </a>
        @endforeach
    </nav>
    @if ($exclusiveCra)
        {{-- Day: the day's goals and leads; Month: the calendar and month goals (switched on the page, segmentation.js) --}}
        <div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="Goals">
            @foreach (['day' => 'Day', 'month' => 'Month'] as $key => $label)
                <button type="button" data-goal-view="{{ $key }}" aria-pressed="{{ ($goalView ?? 'day') === $key ? 'true' : 'false' }}"
                        class="flex h-9 items-center rounded-full border border-line bg-white px-3.5 text-sm font-semibold text-muted hover:text-ink aria-pressed:border-brand-600 aria-pressed:bg-brand-600 aria-pressed:text-white">{{ $label }}</button>
            @endforeach
            @if ($exclusiveCra->is($tabUser) && $tabUser->can('segmentation.view'))
                <a href="{{ route('segmentation.weekly') }}" class="flex h-9 items-center rounded-full border border-line bg-white px-3.5 text-sm font-semibold text-muted hover:text-ink">Weekly Segmentation</a>
            @endif
        </div>
    @endif
</div>

@isset($toolbar)
    {{-- Filters on their own row under the tabs, starting at the left --}}
    <div class="mb-3 shrink-0 rounded-2xl border border-line bg-white p-2">@include($toolbar)</div>
@endisset
