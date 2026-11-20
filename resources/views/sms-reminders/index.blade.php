@php
    use App\Models\RestockReminder;

    $control = 'h-9 rounded-lg border border-line bg-white px-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none';
    $submit = 'this.form.submit()';
    $tz = config('segmentation.timezone');
    $canViewAll = auth()->user()->can('sms_reminders.view_all');
    $status = $filters['status'] ?? null;
@endphp

<x-layouts.app title="SMS Reminders">
    <div class="space-y-4">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">SMS Reminders</h1>
                <p class="text-sm text-muted">Customers whose product runs out on <span class="font-semibold text-ink">{{ $day->addDays($daysBefore)->format('D, M j') }}</span>, texted {{ $daysBefore }} days before from their CRA's own phone.</p>
            </div>
            @include('sms-reminders._tabs', ['openReplies' => $replies])
        </header>

        @if ($errors->any())
            <div role="alert" class="rounded-lg border border-coral/60 bg-coral/10 px-4 py-3 text-sm text-coral-700">{{ $errors->first() }}</div>
        @endif
        @if (session('status'))
            <div role="status" class="rounded-lg border border-teal/50 bg-teal/10 px-4 py-3 text-sm text-teal-700">{{ session('status') }}</div>
        @endif
        @unless ($enabled)
            <div role="status" class="rounded-lg border border-line bg-white px-4 py-3 text-sm">
                SMS Reminders is <span class="font-semibold">off</span>: the list is built every morning, but no texts go out.
                @can('sms_reminders.manage') <a href="{{ route('settings.sms-reminders.index') }}" class="font-semibold text-brand-600 hover:underline">Turn it on in Settings → SMS Reminders</a>. @endcan
            </div>
        @endunless

        <form method="GET" action="{{ route('sms-reminders.index') }}" class="flex flex-wrap items-end gap-3 rounded-xl border border-brand-200 bg-gradient-to-r from-brand-100 to-brand-50 p-4 shadow-sm" aria-label="Day and CRA">
            <label class="flex flex-col gap-1.5 text-xs font-semibold tracking-wide text-muted uppercase">
                Reminder day
                <input type="date" name="date" value="{{ $day->toDateString() }}" onchange="{{ $submit }}" class="{{ $control }} text-ink normal-case">
            </label>
            @if ($canViewAll)
                <label class="flex flex-col gap-1.5 text-xs font-semibold tracking-wide text-muted uppercase">
                    CRA
                    <select name="cra" onchange="{{ $submit }}" class="{{ $control }} text-ink normal-case">
                        <option value="">All CRAs</option>
                        @foreach ($cras as $option)
                            <option value="{{ $option->id }}" @selected($craId === $option->id)>{{ $option->displayName() }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            <div class="flex flex-col gap-1.5">
                <span class="text-xs font-semibold tracking-wide text-muted uppercase">Show</span>
                <div class="flex gap-1.5">
                    @foreach (['' => 'All · '.$counts['all'], 'send' => 'To send · '.$counts['toSend'], 'sent' => 'Sent · '.$counts['sent'], 'out' => 'Left out · '.($counts['excluded'] + $counts['skipped'] + $counts['failed'])] as $value => $name)
                        <a href="{{ request()->fullUrlWithQuery(['status' => $value ?: null, 'page' => null]) }}"
                           @class(['grid h-9 place-items-center rounded-lg border px-3 text-xs font-semibold tabular-nums', 'border-brand-500 bg-brand-50 text-brand-700' => ($status ?? '') === $value, 'border-line bg-white text-muted hover:border-brand-400 hover:text-brand-600' => ($status ?? '') !== $value])>{{ $name }}</a>
                    @endforeach
                </div>
            </div>
            @if ($status)
                <input type="hidden" name="status" value="{{ $status }}">
            @endif
            <noscript><button type="submit" class="h-9 rounded-lg bg-brand-600 px-3 text-xs font-semibold text-white">Apply</button></noscript>
        </form>

        <section aria-label="Sending" class="flex flex-wrap items-center justify-between gap-5 rounded-2xl border border-line bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-center gap-7">
                <div>
                    <p class="text-4xl leading-none font-bold text-brand-700 tabular-nums">{{ number_format($counts['toSend']) }}</p>
                    <p class="mt-1 text-sm font-semibold">customers will get a reminder</p>
                </div>
                <dl class="grid gap-1 text-sm text-muted">
                    <div><dt class="inline">Sent:</dt> <dd class="inline font-semibold text-ink tabular-nums">{{ $counts['sent'] }}</dd>@if ($counts['queued']) · <span class="font-semibold text-[#1a4f8c]">{{ $counts['queued'] }} sending now</span>@endif</div>
                    <div><dt class="inline">Left out by the CRA:</dt> <dd class="inline font-semibold text-ink tabular-nums">{{ $counts['excluded'] }}</dd></div>
                    <div><dt class="inline">Left out by the app (reordered, opted out):</dt> <dd class="inline font-semibold text-ink tabular-nums">{{ $counts['skipped'] }}</dd>@if ($counts['failed']) · <span class="font-semibold text-coral-700">{{ $counts['failed'] }} failed</span>@endif</div>
                </dl>
            </div>

            @if ($isToday)
                @if ($cra)
                    <div class="flex flex-col items-end gap-2">
                        <form method="POST" action="{{ route('sms-reminders.send-now') }}">
                            @csrf
                            <input type="hidden" name="cra" value="{{ $cra->id }}">
                            <button type="submit" @disabled(! $enabled || $counts['toSend'] - $counts['queued'] === 0 || ! $phoneReady)
                                    class="inline-flex h-11 items-center gap-2 rounded-lg bg-brand-600 px-5 font-semibold text-white shadow-sm transition hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-50">
                                <svg class="size-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linejoin="round" d="M4 12 20 4l-6 16-2-6z"/></svg>
                                Send to {{ $counts['toSend'] - $counts['queued'] }} customers now
                            </button>
                        </form>
                        <form method="POST" action="{{ route('sms-reminders.auto-send') }}" class="flex items-center gap-2 text-sm">
                            @csrf @method('PUT')
                            <input type="hidden" name="cra" value="{{ $cra->id }}">
                            <input type="hidden" name="on" value="{{ $cra->sms_auto_send ? 0 : 1 }}">
                            <button type="submit" role="switch" aria-checked="{{ $cra->sms_auto_send ? 'true' : 'false' }}" aria-label="Send automatically every day"
                                    @class(['relative h-6 w-11 shrink-0 rounded-full transition', 'bg-brand-600' => $cra->sms_auto_send, 'bg-line' => ! $cra->sms_auto_send])>
                                <span @class(['absolute top-0.5 size-5 rounded-full bg-white shadow transition-all', 'left-[1.375rem]' => $cra->sms_auto_send, 'left-0.5' => ! $cra->sms_auto_send])></span>
                            </button>
                            <span>{{ $cra->sms_auto_send ? 'Sends automatically every day at '.\Carbon\Carbon::createFromFormat('H:i', $sendTime)->format('g:i A') : 'Automatic sending off' }}</span>
                        </form>
                        @unless ($phoneReady)
                            <p class="text-xs text-coral-700">{{ $cra->displayName() }}'s phone link isn't set in Settings → Call Recordings.</p>
                        @endunless
                    </div>
                @else
                    <p class="max-w-xs text-sm text-muted">Pick a CRA to send their texts or turn on their automatic sending: texts go out from each CRA's own phone.</p>
                @endif
            @endif
        </section>

        <x-panel title="Customers" icon="users" bodyClass="p-0" :tinted="false">
            <x-slot:badges>
                <span class="rounded-full bg-white px-2.5 py-0.5 text-xs font-semibold text-brand-700 shadow-sm tabular-nums">{{ number_format($rows->total()) }}</span>
            </x-slot:badges>
            @if ($rows->isNotEmpty() && $isToday)
                <x-slot:actions>
                    @foreach ([1 => 'Tick all', 0 => 'Untick all'] as $include => $label)
                        <form method="POST" action="{{ route('sms-reminders.bulk') }}">
                            @csrf
                            <input type="hidden" name="date" value="{{ $day->toDateString() }}">
                            <input type="hidden" name="cra" value="{{ $craId }}">
                            <input type="hidden" name="include" value="{{ $include }}">
                            <button type="submit" class="h-8 rounded-lg border border-line bg-white px-3 font-semibold text-ink hover:border-brand-400 hover:text-brand-600">{{ $label }}</button>
                        </form>
                    @endforeach
                </x-slot:actions>
            @endif

            @if ($rows->isEmpty())
                <p class="px-4 py-10 text-center text-sm text-muted">No customers due a reminder on this day{{ $status ? ' with this filter' : '' }}. The list is built every morning at 6:30.</p>
            @else
                <p class="border-b border-line px-4 py-2 text-xs text-muted">Untick a customer to leave them out of the text; they stay on the call list.</p>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[62rem] text-sm">
                        <thead class="bg-canvas/60 text-xs whitespace-nowrap text-muted">
                            <tr>
                                <th scope="col" class="w-10 px-4 py-2"><span class="sr-only">Text this customer</span></th>
                                <th scope="col" class="px-2 py-2 text-left font-semibold">Customer</th>
                                <th scope="col" class="px-2 py-2 text-left font-semibold">Product</th>
                                <th scope="col" class="px-2 py-2 text-left font-semibold">Type</th>
                                @if (! $craId)
                                    <th scope="col" class="px-2 py-2 text-left font-semibold">CRA</th>
                                @endif
                                <th scope="col" class="px-2 py-2 text-left font-semibold">Message</th>
                                <th scope="col" class="px-4 py-2 text-left font-semibold">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($rows as $reminder)
                                @php($ticked = in_array($reminder->status, [RestockReminder::PENDING, RestockReminder::QUEUED, RestockReminder::SENT], true))
                                <tr @class(['align-top', 'bg-canvas/50 text-muted' => ! $ticked])>
                                    <td class="px-4 py-3">
                                        <form method="POST" action="{{ route('sms-reminders.update', $reminder) }}">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="include" value="{{ $ticked ? 0 : 1 }}">
                                            <input type="checkbox" @checked($ticked) @disabled(! $reminder->isChangeable() || ! $isToday) onchange="{{ $submit }}"
                                                   aria-label="Text {{ $reminder->customer_name }}" class="size-4.5 cursor-pointer rounded accent-brand-600 disabled:cursor-not-allowed">
                                        </form>
                                    </td>
                                    <td class="px-2 py-3">
                                        <p class="font-semibold text-ink">{{ $reminder->customer_name ?: 'No name' }}</p>
                                        <p class="text-xs text-muted tabular-nums">{{ \App\Models\CallRecording::dialNumber($reminder->phone_number) }}</p>
                                    </td>
                                    <td class="px-2 py-3">{{ $reminder->products }}</td>
                                    <td class="px-2 py-3 whitespace-nowrap">{{ $reminder->customerTypeLabel() }}</td>
                                    @if (! $craId)
                                        <td class="px-2 py-3 whitespace-nowrap">{{ $reminder->cra?->displayName() ?? 'No CRA' }}</td>
                                    @endif
                                    <td class="max-w-md px-2 py-3">
                                        <details>
                                            <summary class="cursor-pointer text-xs font-semibold text-brand-600 hover:underline">{{ $reminder->status === RestockReminder::SENT ? 'What was sent' : 'Preview' }}</summary>
                                            <p class="mt-1.5 rounded-lg bg-canvas px-3 py-2 text-xs leading-relaxed text-ink">{{ $reminder->preview }}</p>
                                        </details>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap {{ $reminder->statusClasses() }}">{{ $reminder->statusLabel() }}</span>
                                        @if ($reminder->sent_at)
                                            <p class="mt-1 text-xs text-muted">{{ $reminder->sent_at->timezone($tz)->format('g:i A') }}</p>
                                        @elseif ($reminder->status === RestockReminder::EXCLUDED && $isToday)
                                            <form method="POST" action="{{ route('sms-reminders.update', $reminder) }}" class="mt-1">
                                                @csrf @method('PATCH')
                                                <input type="hidden" name="include" value="0">
                                                <label class="sr-only" for="reason-{{ $reminder->id }}">Why left out</label>
                                                <select id="reason-{{ $reminder->id }}" name="reason" onchange="{{ $submit }}" class="h-8 rounded-lg border border-line bg-white px-2 text-xs text-ink">
                                                    @foreach (RestockReminder::EXCLUDE_REASONS as $reason)
                                                        <option @selected($reminder->excluded_reason === $reason)>{{ $reason }}</option>
                                                    @endforeach
                                                </select>
                                            </form>
                                        @elseif ($reminder->error)
                                            <p class="mt-1 max-w-[14rem] text-xs text-muted">{{ $reminder->error }}</p>
                                        @endif
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
