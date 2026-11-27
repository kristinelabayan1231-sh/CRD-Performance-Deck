{{-- Conversations → Follow-ups: texts still to go after the restock reminder. Needs: $groups, $today, $craId, $phone, $query. --}}
<p class="border-b border-line px-4 py-2 text-xs text-muted">Not reordered by 9 PM of the day they're texted: a follow-up goes out {{ \App\Models\RestockReminder::FOLLOW_UP_DAYS }} days later, up to {{ \App\Models\RestockReminder::MAX_FOLLOW_UPS }}; then they move to No response. They stop on a reorder, STOP or cancel.</p>
@if ($groups->isEmpty())
    <p class="px-4 py-10 text-center text-sm text-muted">No follow-ups waiting.</p>
@endif
@foreach ($groups as $group => $followUps)
    <h3 class="border-b border-line bg-canvas/60 px-4 py-1.5 text-xs font-semibold text-muted">{{ $group }}</h3>
    <ul class="divide-y divide-line border-b border-line">
        @foreach ($followUps as $followUp)
            @php([$label, $detail] = $followUp->why())
            <li @class(['grid gap-1.5 px-4 py-3', 'bg-brand-50' => $followUp->phone_key === $phone])>
                <a href="{{ route('sms-reminders.follow-ups.index', $query(['phone' => $followUp->phone_key])) }}" @if ($followUp->phone_key === $phone) aria-current="true" @endif class="block hover:text-brand-600">
                    <span class="font-semibold">{{ $followUp->customer_name ?: 'Customer' }}</span> <span class="text-xs text-muted">· {{ $followUp->products }}</span>
                </a>
                <p class="text-xs text-muted">
                    <span class="rounded-full bg-brand-50 px-2 py-0.5 font-semibold text-brand-700">{{ $label }}</span>
                    {{ $detail }} · {{ $followUp->remind_on->format('D, M j') }}
                    @if ($followUp->note) · Note: {{ $followUp->note }} @endif
                    @if (! $craId && $followUp->user_id) · {{ $followUp->cra?->displayName() }} @endif
                </p>
                <div class="flex gap-2">
                    <form method="POST" action="{{ route('sms-reminders.follow-ups.update', $followUp) }}" class="min-w-0 flex-1">
                        @csrf @method('PATCH')
                        <label class="sr-only" for="day-{{ $followUp->id }}">New day for {{ $followUp->customer_name }}</label>
                        <input id="day-{{ $followUp->id }}" type="date" name="day" min="{{ $today->toDateString() }}" value="{{ $followUp->remind_on->toDateString() }}" onchange="this.form.submit()" class="h-9 w-full rounded-lg border border-line bg-white px-2.5 text-sm">
                    </form>
                    <form method="POST" action="{{ route('sms-reminders.follow-ups.destroy', $followUp) }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="h-9 rounded-lg border border-line bg-white px-3 text-xs font-semibold text-coral-700 hover:border-coral">Cancel</button>
                    </form>
                </div>
            </li>
        @endforeach
    </ul>
@endforeach
