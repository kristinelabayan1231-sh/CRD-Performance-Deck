{{-- One customer's chat: header actions, steps, messages and the reply box. Conversations and the tracker's conversation panel.
     Needs: $thread, $phone, $optedOut, $canDial, $templates, $counts, $earlierUrl. --}}
@php($tz = config('segmentation.timezone'))
{{-- $compact (the tracker's customer panel, whose header already has the customer and Call): small steps, and Follow-up /
     Answered / Mark reordered under the reply box --}}
@php($compact = $compact ?? false)
<section aria-label="Conversation" @class(['flex min-w-0 flex-col', 'h-dvh' => $compact])>
    @if (! $thread)
        <p class="px-4 py-16 text-center text-sm text-muted">Pick a customer to see their texts.</p>
    @else
        @php($latest = $thread['latest'])
        @unless ($compact)
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
        @endunless

        {{-- The reminder and its follow-ups, each with why it didn't bring a reorder --}}
        @if ($thread['steps'] && $compact)
            <ol class="grid grid-cols-2 gap-2 border-b border-line bg-white px-3 py-2.5 text-xs">
                @foreach ($thread['steps'] as $step)
                    <li @class(['flex items-start gap-2', 'hidden' => $loop->index > 1])>
                        <span aria-hidden="true" class="grid size-7 shrink-0 place-items-center rounded-full bg-canvas">
                            <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $loop->first ? 'M4 12 20 4l-6 16-2-6z' : 'M17 2l4 4-4 4M3 11V9a3 3 0 0 1 3-3h15M7 22l-4-4 4-4M21 13v2a3 3 0 0 1-3 3H3' }}"/></svg>
                        </span>
                        <span class="min-w-0 leading-tight"><b class="block font-semibold">{{ $step['label'] }} <span class="tabular-nums">{{ $step['day'] }}</span></b><span class="text-muted">{{ $step['state'] }}</span></span>
                    </li>
                @endforeach
            </ol>
        @elseif ($thread['steps'])
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

        <div @class(['flex flex-col gap-2.5 overflow-y-auto bg-canvas/60 px-4 py-4', 'max-h-[28rem] min-h-64' => ! $compact, 'min-h-0 flex-1' => $compact]) data-chat-scroll>
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
                    <div @class(['max-w-[78%] rounded-2xl px-3 py-2 text-sm', 'self-start rounded-bl-sm bg-ink font-semibold text-white' => $item['type'] === 'in', 'self-end rounded-br-sm bg-[#e4e4e1] text-ink' => $item['type'] === 'out'])>
                        <p class="whitespace-pre-line">{{ $item['body'] }}</p>
                        <p @class(['mt-1 text-[0.6875rem] font-normal', 'text-white/75' => $item['type'] === 'in', 'text-muted' => $item['type'] === 'out'])>{{ $item['meta'] }}</p>
                    </div>
                @endif
            @endforeach
        </div>

        <div @class(['border-t border-line', 'px-4 py-3' => ! $compact, 'shrink-0 px-3 py-2.5' => $compact])>
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
                @if ($compact)
                    <form method="POST" action="{{ route('sms-reminders.conversations.text', $phone) }}" class="grid gap-2">
                        @csrf
                        @if ($templates->isNotEmpty())
                            {{-- A template fills the reply box (filled in for this customer); Write my own leaves it as typed --}}
                            <select aria-label="Template" onchange="if (this.value) { this.form.message.value = this.selectedOptions[0].dataset.body; this.form.message.focus(); }"
                                    class="h-10 w-full rounded-[0.625rem] border-0 bg-canvas px-3 text-sm focus:ring-2 focus:ring-brand-200 focus:outline-none">
                                <option value="">Write my own</option>
                                @foreach ($templates as $template)
                                    <option value="{{ $template->id }}" data-body="{{ app(\App\Services\RestockReminders::class)->fill($template->body, $latest, $latest->cra, false) }}">{{ $template->name }}</option>
                                @endforeach
                            </select>
                        @endif
                        <div class="flex items-end gap-2">
                            <label for="chat-message" class="sr-only">Reply to {{ $latest->firstName() ?: 'the customer' }}</label>
                            <textarea id="chat-message" name="message" rows="2" maxlength="480" required title="Enter sends · Shift+Enter for a new line · not counted in the daily limit"
                                      placeholder="Reply from {{ $latest->cra?->displayName() ?? 'their CRA' }}'s phone"
                                      onkeydown="if (event.key === 'Enter' && ! event.shiftKey) { event.preventDefault(); if (this.value.trim()) this.form.requestSubmit(); }"
                                      class="min-w-0 flex-1 resize-y rounded-xl border border-[#d9d9d6] px-3 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">{{ old('message') }}</textarea>
                            <button type="submit" aria-label="Send reply" title="Send" class="grid size-10 shrink-0 place-items-center rounded-full bg-brand-500 text-ink hover:bg-brand-400">
                                <svg class="size-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linejoin="round" d="M4 12 20 4l-6 16-2-6z"/></svg>
                            </button>
                        </div>
                    </form>
                    <div class="mt-2 flex flex-wrap items-center gap-1.5 [&_summary]:h-9 [&_summary]:rounded-full [&_summary]:border-[#d9d9d6] [&_summary]:bg-white [&_summary]:text-ink">
                        @include('sms-reminders._schedule-follow-up', ['phoneKey' => $phone, 'templates' => $templates, 'idSuffix' => 'chat', 'label' => 'Follow-up'])
                        @if ($counts['reply'])
                            <button type="submit" form="answered-form" class="flex h-9 items-center gap-1.5 rounded-full border border-[#d9d9d6] px-3 text-xs font-semibold hover:border-ink">
                                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7"/></svg>Answered
                            </button>
                        @endif
                        @if ($latest->reordered_at)
                            <button type="submit" form="reordered-undo-form" title="Wrong click, or the order was cancelled: their cancelled texts come back" class="h-9 rounded-full border border-[#d9d9d6] px-3 text-xs font-semibold hover:border-ink">Undo reordered</button>
                        @else
                            <button type="submit" form="reordered-form" class="h-9 rounded-full border border-[#d9d9d6] px-3 text-xs font-semibold hover:border-ink">Mark reordered</button>
                        @endif
                    </div>
                    <form id="reordered-form" method="POST" action="{{ route('sms-reminders.conversations.reordered', $phone) }}" hidden>@csrf</form>
                    <form id="reordered-undo-form" method="POST" action="{{ route('sms-reminders.conversations.reordered.undo', $phone) }}" hidden>@csrf @method('DELETE')</form>
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
                @endif
                <form id="answered-form" method="POST" action="{{ route('sms-reminders.conversations.answered', $phone) }}" hidden>@csrf</form>
            @endif
        </div>
        <script>document.querySelector('[data-chat-scroll]')?.scrollTo(0, 1e6);</script>
    @endif
</section>
