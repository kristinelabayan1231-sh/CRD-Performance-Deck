@props([
    'action',
    'craId',
    'weekStart',
    'pcs',
    'existing' => null,
    'submitLabel' => 'Save cohorts',
    'editableWeek' => true,
])

@php
    $existing ??= collect();
    $monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
@endphp

<form method="POST" action="{{ $action }}" class="space-y-3">
    @csrf
    <input type="hidden" name="cra_id" value="{{ $craId }}">

    @if ($editableWeek)
        <div>
            <label class="block text-xs font-medium text-slate-500">Any date in the target week</label>
            <input type="date" name="week_start" value="{{ $weekStart }}" required
                class="mt-1 rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
            <p class="mt-1 text-[11px] text-slate-400">Snaps to that date's 7-day block (1–7, 8–14, 15–21, 22–28, 29–end).</p>
        </div>
    @else
        <input type="hidden" name="week_start" value="{{ $weekStart }}">
    @endif

    <div class="overflow-hidden rounded-lg border border-slate-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-100 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                    <th class="px-3 py-2 font-medium">PC</th>
                    <th class="px-3 py-2 font-medium">Cohort From</th>
                    <th class="px-3 py-2 font-medium">Cohort To</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($pcs as $i => $pc)
                    @php $row = $existing->get($pc->id); @endphp
                    <tr class="border-b border-slate-50 last:border-0">
                        <td class="px-3 py-2 align-top">
                            <input type="hidden" name="pcs[{{ $i }}][pc_id]" value="{{ $pc->id }}">
                            <span class="block font-medium text-slate-700">{{ $pc->label }}</span>
                            <span class="block text-xs text-slate-400">{{ $pc->facebookPage?->page_name ?? 'No page' }}</span>
                        </td>
                        <td class="px-3 py-2">
                            <div class="flex gap-1">
                                <select name="pcs[{{ $i }}][cohort_from_month]" required
                                    class="rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
                                    @foreach ($monthNames as $mi => $mname)
                                        <option value="{{ $mi + 1 }}" @selected(($row->cohort_from_month ?? now()->month) === $mi + 1)>{{ $mname }}</option>
                                    @endforeach
                                </select>
                                <select name="pcs[{{ $i }}][cohort_from_year]" required
                                    class="rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
                                    @foreach (range(now()->year - 3, now()->year + 1) as $year)
                                        <option value="{{ $year }}" @selected(($row->cohort_from_year ?? now()->year) === $year)>{{ $year }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </td>
                        <td class="px-3 py-2">
                            <div class="flex gap-1">
                                <select name="pcs[{{ $i }}][cohort_to_month]" required
                                    class="rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
                                    @foreach ($monthNames as $mi => $mname)
                                        <option value="{{ $mi + 1 }}" @selected(($row->cohort_to_month ?? now()->month) === $mi + 1)>{{ $mname }}</option>
                                    @endforeach
                                </select>
                                <select name="pcs[{{ $i }}][cohort_to_year]" required
                                    class="rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500">
                                    @foreach (range(now()->year - 3, now()->year + 1) as $year)
                                        <option value="{{ $year }}" @selected(($row->cohort_to_year ?? now()->year) === $year)>{{ $year }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <button type="submit"
        class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-700">
        {{ $submitLabel }}
    </button>
</form>
