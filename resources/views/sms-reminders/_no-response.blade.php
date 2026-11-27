{{-- Conversations → No response: no reorder after every automatic follow-up. Needs: $rows, $sent, $lastReply, $canDial, $craId, $phone, $query. --}}
<p class="border-b border-line px-4 py-2 text-xs text-muted">No reorder after the reminder and {{ \App\Models\RestockReminder::MAX_FOLLOW_UPS }} follow-ups. No more automatic texts: call them, mark them reordered, or text them again by hand.</p>
@if ($rows->isEmpty())
    <p class="px-4 py-10 text-center text-sm text-muted">Nobody here: every customer texted either reordered or still has follow-ups to go.</p>
@else
    @unless ($canDial)
        <p class="border-b border-line px-4 py-2 text-xs text-muted">The Call button shows once your phone link is saved in Settings → Call Recordings.</p>
    @endunless
    <ul class="divide-y divide-line">
        @foreach ($rows as $row)
            <li @class(['grid gap-1.5 px-4 py-3', 'bg-brand-50' => $row->phone_key === $phone])>
                <a href="{{ route('sms-reminders.no-response', $query(['phone' => $row->phone_key])) }}" @if ($row->phone_key === $phone) aria-current="true" @endif class="block hover:text-brand-600">
                    <span class="font-semibold">{{ $row->customer_name ?: 'Customer' }}</span> <span class="text-xs text-muted">· {{ $row->products }}</span>
                </a>
                <p class="text-xs text-muted">{{ \App\Models\CallRecording::dialNumber($row->phone_number) }} · {{ $sent[$row->phone_key] ?? 0 }} texts sent · last reply: {{ $lastReply[$row->phone_key] ?? '—' }} · moved {{ $row->no_response_at->timezone(config('segmentation.timezone'))->format('M j') }}</p>
                <div class="flex flex-wrap items-center gap-1.5">
                    @if ($canDial)
                        <button type="button" data-call-url="{{ route('sms-reminders.conversations.call', $row->phone_key) }}" aria-label="Call {{ $row->customer_name ?: 'customer' }} from your phone"
                                class="flex h-9 items-center rounded-lg bg-teal/15 px-3 text-xs font-semibold text-teal-700 select-none hover:bg-teal/25 disabled:opacity-60"><span data-call-label>Call</span></button>
                    @endif
                    @include('sms-reminders._schedule-follow-up', ['phoneKey' => $row->phone_key, 'label' => 'Text again', 'idSuffix' => 'nr'.$row->id, 'templates' => collect()])
                    <form method="POST" action="{{ route('sms-reminders.conversations.reordered', $row->phone_key) }}">
                        @csrf
                        <button type="submit" class="h-9 rounded-lg border border-line bg-white px-3 text-xs font-semibold hover:border-brand-400 hover:text-brand-600">Mark reordered</button>
                    </form>
                </div>
            </li>
        @endforeach
    </ul>
    @if ($rows->hasPages())
        <div class="border-t border-line px-4 py-3">{{ $rows->links() }}</div>
    @endif
@endif
