@php
    $logins = \App\Support\OnDuty::today()->keyBy('user_id');
    $cras = \App\Services\LeadGenerator::cras()->sortBy(fn ($cra) => $logins->has($cra->id) ? $logins[$cra->id]->logged_in_at->timestamp : PHP_INT_MAX)->values();
@endphp

{{-- Supervisors: every active CRA, on duty today (logged in from the Segmentation Tracker) or not yet. --}}
<x-panel title="On duty today" accent="teal" icon="users" title-id="on-duty-title">
    <x-slot:badges>
        <span class="rounded-full bg-white px-2.5 py-0.5 text-xs font-semibold text-teal-700 shadow-sm">{{ $logins->count() }} of {{ $cras->count() }} CRAs</span>
        <span class="text-xs text-muted">{{ \App\Support\WorkingDate::realToday()->format('D, M j') }} · CRAs log in from the Segmentation Tracker</span>
    </x-slot:badges>
    <ul class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
        @forelse ($cras as $cra)
            @php($login = $logins[$cra->id] ?? null)
            <li @class(['flex items-center gap-2.5 rounded-lg bg-white px-3 py-2 text-sm shadow-sm', 'opacity-60' => ! $login])>
                <x-avatar :user="$cra" class="size-7" />
                <span class="min-w-0 flex-1 truncate font-medium">{{ $cra->displayName() }}</span>
                @if ($login)
                    <span class="flex items-center gap-1 text-xs font-semibold text-teal-700 tabular-nums"><span class="size-1.5 rounded-full bg-teal" aria-hidden="true"></span>{{ $login->logged_in_at->timezone(config('segmentation.timezone'))->format('g:i A') }}</span>
                @else
                    <span class="text-xs text-muted">Not logged in</span>
                @endif
            </li>
        @empty
            <li class="text-sm text-muted">No active CRAs yet.</li>
        @endforelse
    </ul>
</x-panel>
