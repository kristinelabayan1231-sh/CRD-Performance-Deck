{{-- Pending and the follow-up lists (callbacks due, Reactivation, Reserved, Reserved - Pancake) one at a time; follow-ups are catered leads, so never in Pending. Pending full view of the Segmentation and Exclusive Lead Trackers. --}}
@php($list = in_array(request('list'), ['callback', 'reactivation', 'reserved', 'reserved_pancake'], true) ? request('list') : 'pending')
<nav class="mb-3 flex gap-1 overflow-x-auto border-b border-line" aria-label="Pending lists">
    @foreach (['pending' => ['Pending', $leads], 'callback' => ['Call back for today', $callbacks], 'reactivation' => ['Reactivation', $reactivation], 'reserved' => ['Reserved', $reserved], 'reserved_pancake' => ['Reserved - Pancake', $pancakeReserved]] as $key => [$label, $tabLeads])
        <a href="{{ request()->fullUrlWithQuery(['list' => $key === 'pending' ? null : $key, 'page' => null, 'callback_page' => null, 'reactivation_page' => null, 'reserved_page' => null, 'pancake_reserved_page' => null]) }}"
           @if ($list === $key) aria-current="page" @endif
           @class([
               '-mb-px flex shrink-0 items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-semibold transition',
               'border-brand-500 text-brand-600' => $list === $key,
               'border-transparent text-muted hover:text-brand-600' => $list !== $key,
           ])>{{ $label }} <span class="rounded-full bg-canvas px-2 py-0.5 text-xs tabular-nums">{{ number_format($tabLeads->total()) }}</span></a>
    @endforeach
</nav>

{{-- The follow-ups are from any day, so they have no "Listed as" or "Last listed" of the day picked --}}
@if ($list === 'callback')
    @include('segmentation._section', ['leads' => $callbacks, 'id' => 'callback', 'title' => 'Call back for today', 'accent' => 'amber', 'icon' => 'calendar', 'backlogRows' => true, 'sources' => null, 'listedDays' => null,
        'hint' => 'Callback date is today or already passed, with no contact since. A reorder counts today.', 'empty' => 'No callbacks due today.'])
@elseif ($list === 'reactivation')
    @include('segmentation._section', ['leads' => $reactivation, 'id' => 'reactivation', 'title' => 'Reactivation', 'accent' => 'sky', 'icon' => 'users', 'backlogRows' => true, 'sources' => null, 'listedDays' => null,
        'hint' => 'Earlier days still on a follow-up status (anything but Active or Blocked): follow up. A reorder counts today.', 'empty' => 'No follow-ups waiting.'])
@elseif ($list === 'reserved')
    @include('segmentation._section', ['leads' => $reserved, 'id' => 'reserved', 'title' => 'Reserved', 'accent' => 'brand', 'icon' => 'box', 'backlogRows' => true, 'sources' => null, 'listedDays' => null,
        'hint' => 'Repeat Purchase is Reserve, from any lead day.', 'empty' => 'No reserved customers.'])
@elseif ($list === 'reserved_pancake')
    @include('segmentation._pancake-reserved', ['reservations' => $pancakeReserved])
@else
    @include('segmentation._section', ['id' => 'unprocessed', 'title' => 'Pending', 'hint' => 'No status or contact date yet: pick a customer and reach out. Right-click a row to mark it catered.', 'empty' => $pendingEmpty])
@endif
