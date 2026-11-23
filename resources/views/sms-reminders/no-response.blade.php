@php
    use App\Models\RestockReminder;

    $control = 'h-9 rounded-lg border border-line bg-white px-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none';
    $tz = config('segmentation.timezone');
@endphp

<x-layouts.app title="SMS No Response">
    <div class="space-y-4">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">SMS Reminders <span class="text-sm font-normal text-muted">· No response</span></h1>
                <p class="text-sm text-muted">No reorder after the reminder and {{ RestockReminder::MAX_FOLLOW_UPS }} follow-ups. No more automatic texts: call them, mark them reordered, or text them again by hand.</p>
            </div>
            @include('sms-reminders._tabs', ['followUpCount' => $counts['follow_ups'], 'noResponseCount' => $counts['no_response']])
        </header>

        @if (session('status'))
            <div role="status" class="rounded-lg border border-teal/50 bg-teal/10 px-4 py-3 text-sm text-teal-700">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div role="alert" class="rounded-lg border border-coral-300 bg-coral-50 px-4 py-3 text-sm text-coral-700">{{ $errors->first() }}</div>
        @endif

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

        <x-panel title="No response" icon="check" bodyClass="p-0" :tinted="false">
            <x-slot:badges>
                <span class="rounded-full bg-white px-2.5 py-0.5 text-xs font-semibold text-brand-700 shadow-sm tabular-nums">{{ number_format($rows->total()) }}</span>
            </x-slot:badges>
            @if ($rows->isEmpty())
                <p class="px-4 py-10 text-center text-sm text-muted">Nobody here: every customer texted either reordered or still has follow-ups to go.</p>
            @else
                @unless ($canDial)
                    <p class="border-b border-line px-4 py-2 text-xs text-muted">The Call button shows once your phone link is saved in Settings → Call Recordings.</p>
                @endunless
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[52rem] text-left text-sm">
                        <thead class="bg-canvas/60 text-xs tracking-wide text-muted uppercase">
                            <tr>
                                <th class="px-4 py-2.5 font-semibold">Customer</th>
                                <th class="px-4 py-2.5 font-semibold">Product</th>
                                <th class="px-4 py-2.5 font-semibold">Texts sent</th>
                                <th class="px-4 py-2.5 font-semibold">Last reply</th>
                                <th class="px-4 py-2.5 font-semibold">Moved here</th>
                                <th class="px-4 py-2.5"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($rows as $row)
                                <tr>
                                    <td class="px-4 py-3"><p class="font-semibold">{{ $row->customer_name ?: 'Customer' }}</p><p class="text-xs text-muted tabular-nums">{{ \App\Models\CallRecording::dialNumber($row->phone_number) }}</p></td>
                                    <td class="px-4 py-3">{{ $row->products }}</td>
                                    <td class="px-4 py-3 tabular-nums">{{ $sent[$row->phone_key] ?? 0 }}</td>
                                    <td class="px-4 py-3 text-muted">{{ $lastReply[$row->phone_key] ?? '—' }}</td>
                                    <td class="px-4 py-3 text-muted">{{ $row->no_response_at->timezone($tz)->format('M j') }}</td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-wrap items-center justify-end gap-2">
                                            @if ($canDial)
                                                <button type="button" data-call-url="{{ route('sms-reminders.conversations.call', $row->phone_key) }}" aria-label="Call {{ $row->customer_name ?: 'customer' }} from your phone"
                                                        class="flex h-9 items-center gap-1.5 rounded-lg bg-teal/15 px-3 text-xs font-semibold text-teal-700 select-none hover:bg-teal/25 disabled:opacity-60">
                                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/></svg>
                                                    <span data-call-label>Call</span>
                                                </button>
                                            @endif
                                            <a href="{{ route('sms-reminders.conversations.index', ['phone' => $row->phone_key, 'show' => 'all']) }}" class="h-9 rounded-lg bg-brand-50 px-3 py-2 text-xs font-semibold text-brand-700 hover:bg-brand-100">Conversation</a>
                                            @include('sms-reminders._schedule-follow-up', ['phoneKey' => $row->phone_key, 'label' => 'Text again', 'idSuffix' => 'nr'.$row->id])
                                            <form method="POST" action="{{ route('sms-reminders.conversations.reordered', $row->phone_key) }}">
                                                @csrf
                                                <button type="submit" class="h-9 rounded-lg border border-line px-3 text-xs font-semibold hover:border-brand-400 hover:text-brand-600">Mark reordered</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($rows->hasPages())
                    <div class="border-t border-line px-4 py-3">{{ $rows->links() }}</div>
                @endif
            @endif
        </x-panel>
    </div>
</x-layouts.app>
