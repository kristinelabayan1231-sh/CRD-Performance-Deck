@php
    $logins = \App\Support\OnDuty::today();
    $label = $logins->count().' CRA on duty';
    $canList = auth()->user()->can('on_duty.view');
    // Off today: a day off set in My schedule, or not logged in while others have (DutyGoals).
    $today = \App\Support\WorkingDate::realToday();
    $cras = $canList ? \App\Services\LeadGenerator::cras()->keyBy('id') : collect();
    $offToday = $canList ? \App\Support\DutyGoals::offDays($cras, $today, $today)[$today->toDateString()] : [];
@endphp

{{-- Sidebar footer (dark panel): today's CRAs on duty; supervisors click it for who logged in and when. --}}
<div {{ $attributes->merge(['class' => 'px-3 py-3']) }}>
    @if ($canList)
        <details class="group relative">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-2 rounded-lg bg-teal/15 px-3 py-2 text-sm font-semibold text-[#6fe3cc] hover:bg-teal/20 focus-visible:ring-2 focus-visible:ring-teal/40 focus-visible:outline-none [&::-webkit-details-marker]:hidden">
                <span class="flex items-center gap-2"><span class="size-2 rounded-full bg-teal" aria-hidden="true"></span>{{ $label }}</span>
                <svg class="size-4 transition group-open:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m6 15 6-6 6 6"/></svg>
            </summary>
            <div class="absolute right-0 bottom-full left-0 mb-2 rounded-xl border border-line bg-white p-3 text-ink shadow-xl">
                <p class="mb-2 flex justify-between text-xs font-semibold"><span>On duty today · {{ \App\Support\WorkingDate::realToday()->format('M j') }}</span><span class="text-muted">{{ $logins->count() }}</span></p>
                @forelse ($logins as $login)
                    <div class="flex items-center gap-2 py-1 text-sm">
                        <x-avatar :user="$login->user" class="size-6" />
                        <span class="min-w-0 flex-1 truncate">{{ $login->user->displayName() }}</span>
                        <span class="text-xs text-muted tabular-nums">{{ $login->logged_in_at->timezone(config('segmentation.timezone'))->format('g:i A') }}</span>
                    </div>
                @empty
                    <p class="text-sm text-muted">No CRA has logged in yet today.</p>
                @endforelse
                @if ($offToday)
                    <p class="mt-2 mb-1 flex justify-between border-t border-line pt-2 text-xs font-semibold"><span>Off today</span><span class="text-muted">{{ count($offToday) }}</span></p>
                    @foreach ($offToday as $craId => $type)
                        <div class="flex items-center gap-2 py-1 text-sm">
                            <x-avatar :user="$cras[$craId]" class="size-6" />
                            <span class="min-w-0 flex-1 truncate">{{ $cras[$craId]->displayName() }}</span>
                            <x-day-off-label :rest="$type === \App\Models\DutySchedule::REST ? 1 : 0" :leave="$type === \App\Models\DutySchedule::LEAVE ? 1 : 0" :counts="false" />
                        </div>
                    @endforeach
                @endif
                <a href="{{ route('cra-schedule.index') }}" class="mt-2 block border-t border-line pt-2 text-xs font-semibold text-brand-600 hover:underline">CRA Schedule &rarr;</a>
            </div>
        </details>
    @else
        <p class="flex items-center gap-2 rounded-lg bg-teal/15 px-3 py-2 text-sm font-semibold text-[#6fe3cc]">
            <span class="size-2 rounded-full bg-teal" aria-hidden="true"></span>{{ $label }}
        </p>
    @endif
</div>
