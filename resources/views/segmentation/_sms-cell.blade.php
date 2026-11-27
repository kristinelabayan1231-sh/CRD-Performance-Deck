{{-- The Text column: the text's state, and Conversation for texts this user may act on; $smsBadge from RestockReminders::badgesFor.
     Who gets texted is ticked in Today's texts. --}}
@php($smsBadge = $smsBadge ?? null)
@php($mine = $smsBadge && (auth()->user()->can('sms_reminders.view_all') || $smsBadge['cra_id'] === auth()->id()))
<td data-col="sms" class="px-4 py-3">
    @if ($smsBadge)
        <span class="flex items-center gap-2">
            <span title="{{ $smsBadge['title'] }}" class="rounded-full px-2 py-0.5 text-[0.6875rem] font-semibold whitespace-nowrap {{ $smsBadge['classes'] }}">{{ $smsBadge['label'] }}</span>
            @if ($mine)
                <button type="button" data-conversation-open="{{ route('sms-reminders.conversations.chat', $smsBadge['phone_key']) }}" data-conversation-name="{{ $lead->customer_name }}"
                        class="h-7 rounded-full bg-brand-50 px-2.5 text-xs font-semibold text-brand-700 hover:bg-brand-100">Conversation</button>
            @endif
        </span>
    @else
        <span class="text-xs text-muted">—</span>
    @endif
</td>
