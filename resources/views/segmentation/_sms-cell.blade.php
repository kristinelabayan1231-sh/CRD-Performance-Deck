{{-- The Text and Actions columns of a tracker row: the text's state as a pill ($smsBadge from RestockReminders::badgesFor),
     then Call and the conversation (its icon follows the state: order for a YES, reply for a reply, send to text). The
     tick that puts the customer in today's texts is in the name cell (_row). --}}
@php
    $smsBadge = $smsBadge ?? null;
    $mine = $smsBadge && (auth()->user()->can('sms_reminders.view_all') || $smsBadge['cra_id'] === auth()->id());
    $state = $smsBadge['state'] ?? null;
    $pill = match ($state) {
        'yes' => 'bg-brand-600 text-white',
        'replied' => 'bg-brand-500 text-ink',
        'failed', 'stop' => 'border border-coral/50 bg-white text-coral-700',
        default => 'border border-line bg-white text-ink',
    };
    $icons = [
        'yes' => 'M7 11v9H4v-9h3Zm0 0 4-7a2 2 0 0 1 2 2v4h5a2 2 0 0 1 2 2.3l-1.2 6A2 2 0 0 1 16.8 20H7',
        'replied' => 'M4 5h16v11H9l-5 4z',
        'to-text' => 'M4 12 20 4l-6 16-2-6z',
        'sending' => 'M12 7v5l3 2M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18',
        'sent' => 'm5 13 4 4L19 7',
        'failed' => 'M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z',
        'unticked' => 'M8 12h8M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18',
        'stop' => 'M5.6 5.6l12.8 12.8M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18',
    ];
    $action = match ($state) {
        'yes' => ['Order: open the conversation', 'M3 4h2l2.4 10.4a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.5L21 8H6M10 20h.01M17 20h.01', 'order'],
        'replied' => ['Reply in the conversation', 'M9 14 4 9l5-5M4 9h10a6 6 0 0 1 6 6v5', 'conversation'],
        'sent' => ['See the conversation', 'M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12ZM12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6', 'conversation'],
        'failed' => ['Open the conversation to text again', 'M4 4v6h6M20 20v-6h-6M5 15a7 7 0 0 0 12 2M19 9A7 7 0 0 0 7 7', 'conversation'],
        default => ['Text in the conversation', 'M4 12 20 4l-6 16-2-6z', 'conversation'],
    };
    $iconButton = 'grid size-8 place-items-center rounded-full border border-line bg-white text-ink transition hover:border-ink';
@endphp
<td data-col="sms" class="px-3 py-2.5">
    @if ($smsBadge)
        <span title="{{ $smsBadge['label'] }}: {{ $smsBadge['title'] }}"
              class="inline-flex h-7 items-center gap-1.5 rounded-full px-2.5 text-xs font-bold whitespace-nowrap {{ $pill }}">
            <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icons[$state] ?? $icons['to-text'] }}"/></svg>
            {{ $smsBadge['short'] }}
        </span>
    @else
        <span class="text-xs text-muted">—</span>
    @endif
</td>
<td data-col="actions" class="px-3 py-2.5">
    <span class="flex items-center gap-1.5">
        @if (($canEditRow ?? false) && ($canDial ?? false))
            {{-- Dials on the CRA's own phone (MacroDroid), so the call and its recording come back to this lead --}}
            <button type="button" data-call-url="{{ route('segmentation.call', $lead) }}" aria-label="Call {{ $lead->customer_name }} from your phone" title="Call from your phone"
                    class="{{ $iconButton }} select-none disabled:opacity-60">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/></svg>
                <span data-call-label class="sr-only">Call</span>
            </button>
        @endif
        @if ($mine)
            <button type="button" data-conversation-open="{{ route('sms-reminders.conversations.chat', $smsBadge['phone_key']) }}" data-conversation-name="{{ $lead->customer_name }}"
                    data-panel-tab="{{ $action[2] }}" aria-label="{{ $action[0] }} with {{ $lead->customer_name }}" title="{{ $action[0] }}"
                    @class([$iconButton, '!border-brand-600 !bg-brand-600 !text-white' => $state === 'yes'])>
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $action[1] }}"/></svg>
            </button>
        @endif
    </span>
</td>
