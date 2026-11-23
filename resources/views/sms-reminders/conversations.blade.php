@php
    use App\Models\SmsReply;

    $control = 'h-9 rounded-lg border border-line bg-white px-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none';
    $tz = config('segmentation.timezone');
    $query = fn (array $extra) => array_filter([...request()->only(['cra', 'show', 'q']), ...$extra], fn ($value) => $value !== null && $value !== '');
@endphp

<x-layouts.app title="SMS Conversations">
    <div class="space-y-4">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">SMS Reminders <span class="text-sm font-normal text-muted">· Conversations</span></h1>
                <p class="text-sm text-muted">Each customer's texts and replies, like a chat. Reply from here, call, mark them reordered or schedule a follow-up.</p>
            </div>
            @include('sms-reminders._tabs', ['openReplies' => $counts['reply']])
        </header>

        @if (session('status'))
            <div role="status" class="rounded-lg border border-teal/50 bg-teal/10 px-4 py-3 text-sm text-teal-700">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div role="alert" class="rounded-lg border border-coral-300 bg-coral-50 px-4 py-3 text-sm text-coral-700">{{ $errors->first() }}</div>
        @endif

        <div class="grid overflow-hidden rounded-xl bg-white shadow-sm lg:grid-cols-[22rem_1fr]">
            {{-- Customers --}}
            <section aria-label="Customers" class="min-w-0 border-b border-line lg:border-r lg:border-b-0">
                <form method="GET" action="{{ route('sms-reminders.conversations.index') }}" class="grid gap-2 border-b border-line p-3">
                    <input type="search" name="q" value="{{ request('q') }}" placeholder="Search name or number" aria-label="Search conversations" class="{{ $control }}">
                    @can('sms_reminders.view_all')
                        <select name="cra" onchange="this.form.submit()" aria-label="CRA" class="{{ $control }}">
                            <option value="">All CRAs</option>
                            @foreach ($cras as $option)
                                <option value="{{ $option->id }}" @selected($craId === $option->id)>{{ $option->displayName() }}</option>
                            @endforeach
                        </select>
                    @endcan
                    <input type="hidden" name="show" value="{{ $show }}">
                    <div class="flex flex-wrap gap-1.5">
                        @foreach (['reply' => 'Needs reply · '.$counts['reply'], 'reorder' => 'Wants to reorder · '.$counts['reorder'], 'all' => 'All'] as $value => $name)
                            <a href="{{ route('sms-reminders.conversations.index', $query(['show' => $value, 'phone' => null, 'page' => null])) }}"
                               @class(['rounded-lg border px-2.5 py-1 text-xs font-semibold tabular-nums', 'border-brand-500 bg-brand-50 text-brand-700' => $show === $value, 'border-line text-muted hover:border-brand-400 hover:text-brand-600' => $show !== $value])>{{ $name }}</a>
                        @endforeach
                    </div>
                </form>
                @if ($threads->isEmpty())
                    <p class="px-4 py-10 text-center text-sm text-muted">{{ match ($show) { 'reply' => 'No replies waiting for an answer.', 'reorder' => 'Nobody is waiting for a reorder call.', default => 'No texts in the last '.\App\Services\SmsConversations::DAYS.' days.' } }}</p>
                @else
                    <ul class="max-h-[36rem] divide-y divide-line overflow-y-auto">
                        @foreach ($threads as $row)
                            <li>
                                <a href="{{ route('sms-reminders.conversations.index', $query(['phone' => $row['phone_key'], 'page' => $threads->currentPage() > 1 ? $threads->currentPage() : null])) }}"
                                   @if ($row['phone_key'] === $phone) aria-current="true" @endif
                                   @class(['grid gap-1 px-4 py-3 hover:bg-brand-50/60', 'bg-brand-50' => $row['phone_key'] === $phone])>
                                    <span class="flex items-center justify-between gap-2">
                                        <span class="flex min-w-0 items-center gap-2 font-semibold">
                                            @if ($row['open'])
                                                <span aria-label="Needs reply" class="size-2 shrink-0 rounded-full bg-brand-600"></span>
                                            @endif
                                            <span class="truncate">{{ $row['name'] ?: $row['number'] }}</span>
                                        </span>
                                        <span class="shrink-0 text-xs text-muted">{{ $row['last_at']->timezone($tz)->isToday() ? $row['last_at']->timezone($tz)->format('g:i A') : $row['last_at']->timezone($tz)->format('M j') }}</span>
                                    </span>
                                    <span class="truncate text-xs text-muted">{{ $row['last_text'] }}</span>
                                    <span class="flex flex-wrap gap-1">
                                        @if ($row['kind'])
                                            <span class="rounded-full px-2 py-0.5 text-[0.6875rem] font-semibold {{ $row['kind']->kindClasses() }}">{{ $row['kind']->kindLabel() }}</span>
                                        @endif
                                        <span class="rounded-full bg-canvas px-2 py-0.5 text-[0.6875rem] text-muted">{{ $row['products'] }}</span>
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                    @if ($threads->hasPages())
                        <div class="border-t border-line px-3 py-2 text-xs">{{ $threads->links() }}</div>
                    @endif
                @endif
            </section>

            {{-- Chat --}}
            <section aria-label="Conversation" class="flex min-w-0 flex-col">
                @if (! $thread)
                    <p class="px-4 py-16 text-center text-sm text-muted">Pick a customer to see their texts.</p>
                @else
                    @php($latest = $thread['latest'])
                    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-line px-4 py-3">
                        <div class="min-w-0">
                            <h2 class="text-base font-semibold">{{ $latest->customer_name ?: 'Customer' }}</h2>
                            <p class="text-xs text-muted tabular-nums">{{ \App\Models\CallRecording::dialNumber($latest->phone_number) }} · {{ $latest->products }} · CRA {{ $latest->cra?->displayName() ?? 'none' }}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($canDial)
                                <button type="button" data-call-url="{{ route('sms-reminders.conversations.call', $phone) }}" aria-label="Call {{ $latest->customer_name ?: 'customer' }} from your phone"
                                        class="flex h-9 items-center gap-1.5 rounded-lg bg-teal/15 px-3 text-xs font-semibold text-teal-700 select-none hover:bg-teal/25 disabled:opacity-60">
                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/></svg>
                                    <span data-call-label>Call</span>
                                </button>
                            @endif
                            @unless ($optedOut)
                                <form method="POST" action="{{ route('sms-reminders.conversations.reordered', $phone) }}">
                                    @csrf
                                    <button type="submit" class="h-9 rounded-lg border border-line px-3 text-xs font-semibold hover:border-brand-400 hover:text-brand-600">Mark reordered</button>
                                </form>
                                @include('sms-reminders._schedule-follow-up', ['phoneKey' => $phone, 'templates' => $templates, 'idSuffix' => 'chat'])
                            @endunless
                        </div>
                    </div>

                    {{-- The reminder and its follow-ups, each with why it didn't bring a reorder --}}
                    @if ($thread['steps'])
                        <dl class="grid gap-2 border-b border-line bg-canvas/50 px-4 py-3 text-xs sm:grid-cols-2 xl:grid-cols-4">
                            @foreach ($thread['steps'] as $step)
                                <div class="rounded-lg bg-white px-3 py-2 shadow-sm">
                                    <dt class="font-semibold">{{ $step['label'] }}: <span class="tabular-nums">{{ $step['day'] }}</span></dt>
                                    <dd class="text-muted">{{ $step['state'] }}</dd>
                                    @if ($step['id'])
                                        <dd class="mt-1">
                                            <form method="POST" action="{{ route('sms-reminders.conversations.reason', $step['id']) }}" class="flex items-center gap-1">
                                                @csrf @method('PATCH')
                                                <label class="sr-only" for="reason-{{ $step['id'] }}">No reorder reason for {{ $step['label'] }}</label>
                                                <input id="reason-{{ $step['id'] }}" name="reason" value="{{ $step['reason_from'] === 'CRA' ? $step['reason'] : '' }}" maxlength="200"
                                                       placeholder="{{ $step['reason'] ? 'Reply: '.$step['reason'] : 'No reorder reason' }}"
                                                       onchange="this.form.submit()" class="h-7 w-full min-w-0 rounded-md border border-line px-2 text-xs">
                                            </form>
                                            @if ($step['reason'])
                                                <span class="mt-0.5 block text-[0.6875rem] text-muted">No reorder reason: {{ $step['reason'] }}{{ $step['reason_from'] === 'Customer' ? ' (their reply)' : '' }}</span>
                                            @endif
                                        </dd>
                                    @endif
                                </div>
                            @endforeach
                        </dl>
                    @endif

                    <div class="flex max-h-[28rem] min-h-64 flex-col gap-2.5 overflow-y-auto bg-canvas/60 px-4 py-4" data-chat-scroll>
                        @if ($thread['earlier'])
                            <a href="{{ route('sms-reminders.conversations.index', $query(['phone' => $phone, 'all' => 1])) }}" class="self-center rounded-full bg-white px-3 py-1 text-xs font-semibold text-brand-700 shadow-sm hover:underline">Show earlier messages ({{ $thread['earlier'] }})</a>
                        @endif
                        @php($day = null)
                        @foreach ($thread['items'] as $item)
                            @php($itemDay = $item['at']->timezone($tz)->format('D, M j'))
                            @if ($itemDay !== $day)
                                <span class="self-center rounded-full bg-white px-2.5 py-0.5 text-[0.6875rem] text-muted">{{ $itemDay }}</span>
                                @php($day = $itemDay)
                            @endif
                            @if ($item['type'] === 'event')
                                <p class="self-center text-center text-xs text-muted">● {{ $item['body'] }}</p>
                            @else
                                <div @class(['max-w-[78%] rounded-2xl px-3 py-2 text-sm', 'self-start rounded-bl-sm bg-white shadow-sm' => $item['type'] === 'in', 'self-end rounded-br-sm bg-brand-600 text-white' => $item['type'] === 'out'])>
                                    <p class="whitespace-pre-line">{{ $item['body'] }}</p>
                                    <p @class(['mt-1 text-[0.6875rem]', 'text-muted' => $item['type'] === 'in', 'text-white/80' => $item['type'] === 'out'])>{{ $item['meta'] }}</p>
                                </div>
                            @endif
                        @endforeach
                    </div>

                    <div class="border-t border-line px-4 py-3">
                        @if ($optedOut)
                            <p class="text-sm text-coral-700">This customer replied STOP. They are never texted again.</p>
                        @else
                            <form method="POST" action="{{ route('sms-reminders.conversations.text', $phone) }}" class="grid gap-2">
                                @csrf
                                <label for="chat-message" class="sr-only">Reply to {{ $latest->firstName() ?: 'the customer' }}</label>
                                <textarea id="chat-message" name="message" rows="2" maxlength="480" required
                                          placeholder="Reply to {{ $latest->firstName() ?: 'the customer' }}… (sends from {{ $latest->cra?->displayName() ?? 'their CRA' }}'s phone)"
                                          onkeydown="if (event.key === 'Enter' && ! event.shiftKey) { event.preventDefault(); if (this.value.trim()) this.form.requestSubmit(); }"
                                          class="w-full resize-y rounded-lg border border-line px-3 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">{{ old('message') }}</textarea>
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="text-xs text-muted">Enter sends · Shift+Enter for a new line · not counted in the daily limit</span>
                                    <div class="flex items-center gap-2">
                                        @if ($thread['latest'] && $counts['reply'])
                                            <button type="submit" form="answered-form" class="h-9 rounded-lg border border-line px-3 text-xs font-semibold hover:border-brand-400 hover:text-brand-600">Mark answered</button>
                                        @endif
                                        <button type="submit" class="h-9 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">Send</button>
                                    </div>
                                </div>
                            </form>
                            <form id="answered-form" method="POST" action="{{ route('sms-reminders.conversations.answered', $phone) }}" hidden>@csrf</form>
                        @endif
                    </div>
                    <script>document.querySelector('[data-chat-scroll]')?.scrollTo(0, 1e6);</script>
                @endif
            </section>
        </div>
    </div>
</x-layouts.app>
