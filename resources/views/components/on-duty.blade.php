@php
    $logins = \App\Support\OnDuty::today();
    $label = $logins->count().' CRA on duty';
    $canList = auth()->user()->can('on_duty.view');
    // Off today: a day off set in My schedule, or not logged in while others have (DutyGoals).
    $today = \App\Support\WorkingDate::realToday();
    $cras = $canList ? \App\Services\LeadGenerator::cras()->keyBy('id') : collect();
    $offToday = $canList ? \App\Support\DutyGoals::offDays($cras, $today, $today)[$today->toDateString()] : [];
@endphp

{{-- Top bar pill: today's CRAs on duty; supervisors click it for who logged in and when, and who is off. --}}
<div {{ $attributes->merge(['class' => 'shrink-0']) }}>
    @if ($canList)
        <details class="group relative">
            <summary class="flex h-9 cursor-pointer list-none items-center gap-2 rounded-full border border-line bg-white px-3.5 text-[0.8125rem] font-semibold text-ink hover:border-brand-500 focus-visible:ring-2 focus-visible:ring-brand-200 focus-visible:outline-none [&::-webkit-details-marker]:hidden">
                <span class="flex items-center gap-2"><span class="size-2 rounded-full bg-teal" aria-hidden="true"></span>{{ $label }}</span>
                <svg class="size-3.5 text-muted transition group-open:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/></svg>
            </summary>
            <div class="absolute top-full left-0 z-50 mt-2 w-72 rounded-xl border border-line bg-white p-3 text-ink shadow-xl">
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
                            <span @class(['inline-block rounded-full px-2 py-0.5 text-[0.6875rem] font-semibold whitespace-nowrap', \App\Models\DutySchedule::LOOKS[$type]['tag']])>{{ \App\Models\DutySchedule::TYPES[$type] }}</span>
                        </div>
                    @endforeach
                @endif
                <a href="{{ route('cra-schedule.index') }}" class="mt-2 block border-t border-line pt-2 text-xs font-semibold text-brand-600 hover:underline">CRA Schedule &rarr;</a>
            </div>
        </details>
    @else
        <p class="flex h-9 items-center gap-2 rounded-full border border-line bg-white px-3.5 text-[0.8125rem] font-semibold text-ink">
            <span class="size-2 rounded-full bg-teal" aria-hidden="true"></span>{{ $label }}
        </p>
    @endif
</div>
