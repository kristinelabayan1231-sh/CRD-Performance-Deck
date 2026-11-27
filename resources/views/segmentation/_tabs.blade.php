{{-- The tracker's tabs: Leads (with goals on the Exclusive Lead Tracker), Conversations (replies, follow-ups, no response),
     Today's texts and Text settings; the views of a tab (Daily / Weekly, Day / Month, Templates / Schedule) are pills under it.
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
<div @class(['shrink-0', 'mb-3' => ! isset($toolbar), 'mb-2' => isset($toolbar)])>
    <nav class="flex gap-1 overflow-x-auto border-b border-line" aria-label="Tracker sections">
        @foreach ($tabs as [$label, $href, $active, $badge])
            <a href="{{ $href }}" @if ($active) aria-current="page" @endif
               @class([
                   'flex shrink-0 items-center gap-2 border-b-[3px] px-5 py-3 text-base font-semibold transition',
                   'border-brand-600 text-brand-700' => $active,
                   'border-transparent text-muted hover:text-brand-600' => ! $active,
               ])>
                {{ $label }}
                @if ($badge)
                    <span title="Replies to answer" class="rounded-full bg-coral-700 px-1.5 text-xs text-white tabular-nums">{{ number_format($badge) }}</span>
                @endif
            </a>
        @endforeach
    </nav>
    @if ($views || $exclusiveCra)
        @php($pill = fn (bool $active) => 'flex h-9 items-center rounded-lg border px-3.5 text-sm font-semibold '.($active ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-line bg-white hover:border-brand-400 hover:text-brand-600'))
        <nav class="mt-3 flex flex-wrap gap-1.5" aria-label="Views">
            @foreach ($views as [$label, $href, $active])
                <a href="{{ $href }}" @if ($active) aria-current="page" @endif class="{{ $pill($active) }}">{{ $label }}</a>
            @endforeach
            @if ($exclusiveCra)
                {{-- Day: the day's goals and leads; Month: the calendar and month goals (switched on the page, segmentation.js) --}}
                @foreach (['day' => 'Day', 'month' => 'Month'] as $key => $label)
                    <button type="button" data-goal-view="{{ $key }}" aria-pressed="{{ ($goalView ?? 'day') === $key ? 'true' : 'false' }}"
                            class="flex h-9 items-center rounded-lg border border-line bg-white px-3.5 text-sm font-semibold hover:border-brand-400 hover:text-brand-600 aria-pressed:border-brand-500 aria-pressed:bg-brand-50 aria-pressed:text-brand-700">{{ $label }}</button>
                @endforeach
                @if ($exclusiveCra->is($tabUser) && $tabUser->can('segmentation.view'))
                    <a href="{{ route('segmentation.weekly') }}" class="{{ $pill(false) }}">Weekly Segmentation</a>
                @endif
            @endif
        </nav>
    @endif
</div>

@isset($toolbar)
    {{-- Filters on their own row under the tabs, starting at the left --}}
    <div class="mb-3 shrink-0">@include($toolbar)</div>
@endisset
