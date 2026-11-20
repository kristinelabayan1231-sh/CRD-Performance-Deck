@php
    $control = 'h-9 rounded-lg border border-line bg-white px-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none';
    $tz = config('segmentation.timezone');
    $show = $filters['show'] ?? 'open';
@endphp

<x-layouts.app title="SMS Replies">
    <div class="space-y-4">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">SMS Reminders <span class="text-sm font-normal text-muted">· Replies</span></h1>
                <p class="text-sm text-muted">Customers' answers to their restock text. STOP is handled for you (never texted again); YES wants to reorder.</p>
            </div>
            @include('sms-reminders._tabs')
        </header>

        @if (session('status'))
            <div role="status" class="rounded-lg border border-teal/50 bg-teal/10 px-4 py-3 text-sm text-teal-700">{{ session('status') }}</div>
        @endif

        <form method="GET" action="{{ route('sms-reminders.replies.index') }}" class="flex flex-wrap items-end gap-3 rounded-xl border border-brand-200 bg-gradient-to-r from-brand-100 to-brand-50 p-4 shadow-sm" aria-label="Filters">
            @can('sms_reminders.view_all')
                <label class="flex flex-col gap-1.5 text-xs font-semibold tracking-wide text-muted uppercase">
                    CRA
                    <select name="cra" onchange="this.form.submit()" class="{{ $control }} text-ink normal-case">
                        <option value="">All CRAs</option>
                        @foreach ($cras as $option)
                            <option value="{{ $option->id }}" @selected($craId === $option->id)>{{ $option->displayName() }}</option>
                        @endforeach
                    </select>
                </label>
            @endcan
            <div class="flex gap-1.5">
                @foreach (['open' => 'To answer', 'all' => 'All replies'] as $value => $name)
                    <a href="{{ request()->fullUrlWithQuery(['show' => $value, 'page' => null]) }}"
                       @class(['grid h-9 place-items-center rounded-lg border px-3 text-xs font-semibold', 'border-brand-500 bg-brand-50 text-brand-700' => $show === $value, 'border-line bg-white text-muted hover:border-brand-400 hover:text-brand-600' => $show !== $value])>{{ $name }}</a>
                @endforeach
            </div>
        </form>

        <x-panel title="Replies" icon="check" bodyClass="p-0" :tinted="false">
            @if ($replies->isEmpty())
                <p class="px-4 py-10 text-center text-sm text-muted">{{ $show === 'open' ? 'No replies waiting for an answer.' : 'No replies yet.' }}</p>
            @else
                <ul class="divide-y divide-line">
                    @foreach ($replies as $reply)
                        <li @class(['flex flex-wrap items-start justify-between gap-3 px-4 py-3', 'opacity-70' => $reply->handled_at])>
                            <div class="min-w-0 flex-1 space-y-1">
                                <p class="flex flex-wrap items-center gap-2">
                                    <span class="font-semibold">{{ $reply->reminder?->customer_name ?: 'Customer' }}</span>
                                    <span class="text-xs text-muted tabular-nums">{{ \App\Models\CallRecording::dialNumber($reply->phone_number) }}</span>
                                    <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $reply->kindClasses() }}">{{ $reply->kindLabel() }}</span>
                                </p>
                                <p class="rounded-lg bg-canvas px-3 py-2 text-sm">{{ $reply->body }}</p>
                                <p class="text-xs text-muted">
                                    {{ $reply->received_at->timezone($tz)->format('M j, g:i A') }}
                                    @if ($reply->reminder) · about {{ $reply->reminder->products }} @endif
                                    @if (! $craId && $reply->cra) · {{ $reply->cra->displayName() }} @endif
                                    @if ($reply->handled_at && $reply->handler) · handled by {{ $reply->handler->displayName() }} @endif
                                </p>
                            </div>
                            @if ($reply->kind !== \App\Models\SmsReply::STOP)
                                <form method="POST" action="{{ route('sms-reminders.replies.handle', $reply) }}">
                                    @csrf @method('PATCH')
                                    <button type="submit" class="h-9 rounded-lg border border-line bg-white px-3 text-xs font-semibold hover:border-brand-400 hover:text-brand-600">
                                        {{ $reply->handled_at ? 'Reopen' : 'Mark handled' }}
                                    </button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @if ($replies->hasPages())
                    <div class="border-t border-line px-4 py-3">{{ $replies->links() }}</div>
                @endif
            @endif
        </x-panel>
    </div>
</x-layouts.app>
