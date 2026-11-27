{{-- One customer's chat: header actions, steps, messages and the reply box. Conversations and the tracker's conversation panel.
     Needs: $thread, $phone, $optedOut, $canDial, $templates, $counts, $earlierUrl. --}}
@php($tz = config('segmentation.timezone'))
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
@if ($latest->reordered_at)
<form method="POST" action="{{ route('sms-reminders.conversations.reordered.undo', $phone) }}">
    @csrf @method('DELETE')
    <button type="submit" title="Wrong click, or the order was cancelled: their cancelled texts come back" class="h-9 rounded-lg border border-line px-3 text-xs font-semibold hover:border-brand-400 hover:text-brand-600">Undo reordered</button>
</form>
@else
                    <form method="POST" action="{{ route('sms-reminders.conversations.reordered', $phone) }}">
                        @csrf
                        <button type="submit" class="h-9 rounded-lg border border-line px-3 text-xs font-semibold hover:border-brand-400 hover:text-brand-600">Mark reordered</button>
                    </form>
@endif
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
                <a href="{{ $earlierUrl }}" class="self-center rounded-full bg-white px-3 py-1 text-xs font-semibold text-brand-700 shadow-sm hover:underline">Show earlier messages ({{ $thread['earlier'] }})</a>
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
                <form method="POST" action="{{ route('sms-reminders.conversations.allow', $phone) }}" class="flex flex-wrap items-center justify-between gap-2">
                    @csrf
                    <p class="text-sm text-coral-700">This customer asked not to be texted: they get no texts.</p>
                    <label class="flex items-center gap-2 text-sm font-semibold">
                        <input type="checkbox" data-sms-allow="{{ $latest->customer_name }}" class="size-4.5 cursor-pointer rounded accent-brand-600">
                        Text them again
                    </label>
                </form>
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
