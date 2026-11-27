@php
    use App\Models\SmsReply;

    $control = 'h-9 rounded-lg border border-line bg-white px-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none';
    $tz = config('segmentation.timezone');
    $query = fn (array $extra) => array_filter([...request()->only(['cra', 'show', 'q']), ...$extra], fn ($value) => $value !== null && $value !== '');
@endphp

<x-layouts.app title="SMS Conversations">
    <div class="space-y-4">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">{{ auth()->user()->isExclusiveOn(\App\Models\Lead::today()) ? 'Exclusive Lead Tracker' : 'Segmentation Tracker' }} <span class="text-sm font-normal text-muted">· SMS Conversations</span></h1>
                <p class="text-sm text-muted">Answer replies, and see the follow-ups still to go and who never responded.</p>
            </div>
        </header>
        @include('segmentation._tabs', ['openReplies' => $counts['reply']])

        @if (session('status'))
            <div role="status" class="rounded-lg border border-teal/50 bg-teal/10 px-4 py-3 text-sm text-teal-700">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div role="alert" class="rounded-lg border border-coral-300 bg-coral-50 px-4 py-3 text-sm text-coral-700">{{ $errors->first() }}</div>
        @endif

        <div class="grid rounded-xl border border-line bg-white shadow-sm lg:grid-cols-[24rem_1fr]">
            {{-- Customers --}}
            <section aria-label="Customers" class="min-w-0 border-b border-line lg:border-r lg:border-b-0">
                <div class="grid gap-2 border-b border-line p-3">
                    <nav class="grid grid-cols-3 gap-1.5" aria-label="Lists">
                        @foreach (['replies' => ['Replies', 'sms-reminders.conversations.index', $counts['reply']], 'follow-ups' => ['Follow-ups', 'sms-reminders.follow-ups.index', $counts['follow_ups']], 'no-response' => ['No response', 'sms-reminders.no-response', $counts['no_response']]] as $key => [$name, $route, $count])
                            <a href="{{ route($route, array_filter(['cra' => request('cra')])) }}" @if ($box === $key) aria-current="page" @endif
                               @class(['grid h-9 place-items-center rounded-lg border px-1 text-xs font-semibold tabular-nums', 'border-brand-500 bg-brand-50 text-brand-700' => $box === $key, 'border-line text-muted hover:border-brand-400 hover:text-brand-600' => $box !== $key])>{{ $name }} · {{ number_format($count) }}</a>
                        @endforeach
                    </nav>
                    @if ($box !== 'replies' && auth()->user()->can('sms_reminders.view_all'))
                        <form method="GET">
                            <select name="cra" onchange="this.form.submit()" aria-label="CRA" class="{{ $control }} w-full">
                                <option value="">All CRAs</option>
                                @foreach ($cras as $option)
                                    <option value="{{ $option->id }}" @selected($craId === $option->id)>{{ $option->displayName() }}</option>
                                @endforeach
                            </select>
                        </form>
                    @endif
                </div>
                @if ($box === 'follow-ups')
                    <div class="max-h-[44rem] overflow-y-auto">@include('sms-reminders._follow-ups')</div>
                @elseif ($box === 'no-response')
                    @include('sms-reminders._no-response')
                @else
                <form method="GET" action="{{ route('sms-reminders.conversations.index') }}" class="grid gap-2 border-b border-line p-3">
                    <input type="search" name="q" value="{{ request('q') }}" placeholder="Search name or number" aria-label="Search conversations" class="{{ $control }}">
                    @can('sms_reminders.view_all')
                        <select name="cra" onchange="this.form.submit()" aria-label="CRA" class="{{ $control }}">
                            <option value="">All CRAs</option>
                            @foreach ($cras as $option)
                                <option value="{{ $option->id }}" @selected($craId === $option->id)>{{ $option->displayName() }}</option>
                            @endforeach
                        </select>
                    @endcan
                    <input type="hidden" name="show" value="{{ $show }}">
                    <div class="flex flex-wrap gap-1.5">
                        @foreach (['reply' => 'Needs reply · '.$counts['reply'], 'reorder' => 'Wants to reorder · '.$counts['reorder'], 'all' => 'All'] as $value => $name)
                            <a href="{{ route('sms-reminders.conversations.index', $query(['show' => $value, 'phone' => null, 'page' => null])) }}"
                               @class(['rounded-lg border px-2.5 py-1 text-xs font-semibold tabular-nums', 'border-brand-500 bg-brand-50 text-brand-700' => $show === $value, 'border-line text-muted hover:border-brand-400 hover:text-brand-600' => $show !== $value])>{{ $name }}</a>
                        @endforeach
                    </div>
                </form>
                @if ($threads->isEmpty())
                    <p class="px-4 py-10 text-center text-sm text-muted">{{ match ($show) { 'reply' => 'No replies waiting for an answer.', 'reorder' => 'Nobody is waiting for a reorder call.', default => 'No texts in the last '.\App\Services\SmsConversations::DAYS.' days.' } }}</p>
                @else
                    <ul class="max-h-[36rem] divide-y divide-line overflow-y-auto">
                        @foreach ($threads as $row)
                            <li>
                                <a href="{{ route('sms-reminders.conversations.index', $query(['phone' => $row['phone_key'], 'page' => $threads->currentPage() > 1 ? $threads->currentPage() : null])) }}"
                                   @if ($row['phone_key'] === $phone) aria-current="true" @endif
                                   @class(['grid gap-1 px-4 py-3 hover:bg-brand-50/60', 'bg-brand-50' => $row['phone_key'] === $phone])>
                                    <span class="flex items-center justify-between gap-2">
                                        <span class="flex min-w-0 items-center gap-2 font-semibold">
                                            @if ($row['open'])
                                                <span aria-label="Needs reply" class="size-2 shrink-0 rounded-full bg-brand-600"></span>
                                            @endif
                                            <span class="truncate">{{ $row['name'] ?: $row['number'] }}</span>
                                        </span>
                                        <span class="shrink-0 text-xs text-muted">{{ $row['last_at']->timezone($tz)->isToday() ? $row['last_at']->timezone($tz)->format('g:i A') : $row['last_at']->timezone($tz)->format('M j') }}</span>
                                    </span>
                                    <span class="truncate text-xs text-muted">{{ $row['last_text'] }}</span>
                                    <span class="flex flex-wrap gap-1">
                                        @if ($row['kind'])
                                            <span class="rounded-full px-2 py-0.5 text-[0.6875rem] font-semibold {{ $row['kind']->kindClasses() }}">{{ $row['kind']->kindLabel() }}</span>
                                        @endif
                                        <span class="rounded-full bg-canvas px-2 py-0.5 text-[0.6875rem] text-muted">{{ $row['products'] }}</span>
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                    @if ($threads->hasPages())
                        <div class="border-t border-line px-3 py-2 text-xs">{{ $threads->links() }}</div>
                    @endif
                @endif
                @endif
            </section>

            {{-- Chat --}}
            @include('sms-reminders._chat', ['earlierUrl' => $phone ? request()->fullUrlWithQuery(['phone' => $phone, 'all' => 1]) : null])
        </div>
    </div>
</x-layouts.app>
