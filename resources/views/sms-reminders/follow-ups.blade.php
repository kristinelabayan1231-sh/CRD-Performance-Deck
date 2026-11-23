@php
    use App\Models\RestockReminder;

    $control = 'h-9 rounded-lg border border-line bg-white px-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none';
@endphp

<x-layouts.app title="SMS Follow-ups">
    <div class="space-y-4">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">SMS Reminders <span class="text-sm font-normal text-muted">· Follow-ups</span></h1>
                <p class="text-sm text-muted">Texts still to go after the restock reminder: automatic ones and ones scheduled by the CRA.</p>
            </div>
            @include('sms-reminders._tabs', ['followUpCount' => $counts['follow_ups'], 'noResponseCount' => $counts['no_response']])
        </header>

        @if (session('status'))
            <div role="status" class="rounded-lg border border-teal/50 bg-teal/10 px-4 py-3 text-sm text-teal-700">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div role="alert" class="rounded-lg border border-coral-300 bg-coral-50 px-4 py-3 text-sm text-coral-700">{{ $errors->first() }}</div>
        @endif

        <section class="grid gap-3 rounded-xl bg-white p-4 shadow-sm md:grid-cols-3">
            <div class="rounded-lg bg-canvas px-3 py-2.5 text-sm"><p class="font-semibold">Added at 9 PM</p><p class="text-muted">Texted today and not reordered (no Repeat Purchase = Yes, no new order)? A follow-up goes out {{ RestockReminder::FOLLOW_UP_DAYS }} days after that text.</p></div>
            <div class="rounded-lg bg-canvas px-3 py-2.5 text-sm"><p class="font-semibold">Scheduled by the CRA</p><p class="text-muted">From a conversation or Today's texts: a day, a note and the text. It doesn't use up an automatic one.</p></div>
            <div class="rounded-lg bg-canvas px-3 py-2.5 text-sm"><p class="font-semibold">When they stop</p><p class="text-muted">Reordered, STOP or cancelled. After the {{ RestockReminder::MAX_FOLLOW_UPS }}rd follow-up with no reorder, the customer moves to No response.</p></div>
        </section>

        @can('sms_reminders.view_all')
            <form method="GET" class="flex flex-wrap items-end gap-3 rounded-xl border border-brand-200 bg-gradient-to-r from-brand-100 to-brand-50 p-4 shadow-sm" aria-label="Filters">
                <label class="flex flex-col gap-1.5 text-xs font-semibold tracking-wide text-muted uppercase">
                    CRA
                    <select name="cra" onchange="this.form.submit()" class="{{ $control }} text-ink normal-case">
                        <option value="">All CRAs</option>
                        @foreach ($cras as $option)
                            <option value="{{ $option->id }}" @selected($craId === $option->id)>{{ $option->displayName() }}</option>
                        @endforeach
                    </select>
                </label>
            </form>
        @endcan

        <x-panel title="Follow-ups to send" icon="check" bodyClass="p-0" :tinted="false">
            <x-slot:badges>
                <span class="rounded-full bg-white px-2.5 py-0.5 text-xs font-semibold text-brand-700 shadow-sm tabular-nums">{{ number_format($counts['follow_ups']) }}</span>
            </x-slot:badges>
            @if ($groups->isEmpty())
                <p class="px-4 py-10 text-center text-sm text-muted">No follow-ups waiting.</p>
            @else
                @foreach ($groups as $group => $followUps)
                    <h2 class="border-t border-line bg-canvas/60 px-4 py-2 text-xs font-bold tracking-wide text-muted uppercase first:border-t-0">{{ $group }}</h2>
                    <ul class="divide-y divide-line">
                        @foreach ($followUps as $followUp)
                            @php([$label, $detail] = $followUp->why())
                            <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                <div class="min-w-0">
                                    <p class="font-semibold">{{ $followUp->customer_name ?: 'Customer' }} <span class="text-xs font-normal text-muted">· {{ $followUp->products }}</span></p>
                                    <p class="text-xs text-muted">
                                        <span class="rounded-full bg-brand-50 px-2 py-0.5 font-semibold text-brand-700">{{ $label }}</span>
                                        {{ $detail }} · {{ $followUp->remind_on->format('D, M j') }}
                                        @if ($followUp->note) · Note: {{ $followUp->note }} @endif
                                        @if (! $craId && $followUp->user_id) · {{ $followUp->cra?->displayName() }} @endif
                                    </p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <a href="{{ route('sms-reminders.conversations.index', ['phone' => $followUp->phone_key, 'show' => 'all']) }}" class="h-9 rounded-lg bg-brand-50 px-3 py-2 text-xs font-semibold text-brand-700 hover:bg-brand-100">Conversation</a>
                                    <form method="POST" action="{{ route('sms-reminders.follow-ups.update', $followUp) }}" class="flex items-center gap-1">
                                        @csrf @method('PATCH')
                                        <label class="sr-only" for="day-{{ $followUp->id }}">New day for {{ $followUp->customer_name }}</label>
                                        <input id="day-{{ $followUp->id }}" type="date" name="day" min="{{ $today->toDateString() }}" value="{{ $followUp->remind_on->toDateString() }}" onchange="this.form.submit()" class="h-9 rounded-lg border border-line px-2 text-xs">
                                    </form>
                                    <form method="POST" action="{{ route('sms-reminders.follow-ups.destroy', $followUp) }}">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="h-9 rounded-lg border border-line px-3 text-xs font-semibold hover:border-coral-400 hover:text-coral-700">Cancel</button>
                                    </form>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endforeach
            @endif
        </x-panel>
    </div>
</x-layouts.app>
