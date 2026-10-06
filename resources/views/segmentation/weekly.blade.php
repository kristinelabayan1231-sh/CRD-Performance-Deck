<x-layouts.app title="Weekly Segmentation">
    @php($control = 'h-10 rounded-lg border border-line bg-white px-3 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none')

    <h1 class="mb-4 text-xl font-semibold">Segmentation Tracker <span class="text-sm font-normal text-muted">· Handled vs. unprocessed leads per CRA, week by week.</span></h1>

    @include('segmentation._tabs')

    @error('transfer')
        <div role="alert" class="mb-4 rounded-lg border border-coral/60 bg-coral/10 px-4 py-3 text-sm text-coral-700">{{ $message }}</div>
    @enderror

    {{-- Filters: month, week of the month, CRA --}}
    <form method="GET" action="{{ route('segmentation.weekly') }}" class="mb-4 flex flex-wrap items-end gap-3 rounded-xl bg-white p-4 shadow-sm">
        <label>
            <span class="mb-1 block text-xs font-medium text-muted">Month</span>
            <input type="month" name="month" value="{{ $filters['month'] }}" class="{{ $control }}"
                   onchange="this.form.querySelector('input[type=hidden][name=week]').value=''; this.form.submit()">
        </label>
        <input type="hidden" name="week" value="{{ $filters['week'] }}">
        <div>
            <span class="mb-1 block text-xs font-medium text-muted">Week</span>
            <div class="flex flex-wrap gap-1" role="group" aria-label="Week of {{ $month->format('F') }}">
                @foreach ($weeks as $w)
                    <button type="submit" name="week" value="{{ $w['number'] }}" @if ($w['number'] === $week['number']) aria-pressed="true" @endif
                            @class([
                                'h-10 rounded-lg px-3 text-sm font-medium transition',
                                'bg-brand-600 text-white' => $w['number'] === $week['number'],
                                'border border-line bg-white hover:border-brand-400 hover:text-brand-600' => $w['number'] !== $week['number'],
                            ])>{{ $w['label'] }}</button>
                @endforeach
            </div>
        </div>
        @if ($canViewAll)
            <label>
                <span class="mb-1 block text-xs font-medium text-muted">CRA</span>
                <select name="cra" class="{{ $control }}" onchange="this.form.submit()">
                    <option value="all" @selected($filters['cra'] === 'all')>All</option>
                    @foreach ($cras as $cra)
                        <option value="{{ $cra->id }}" @selected($filters['cra'] === (string) $cra->id)>{{ $cra->displayName() }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        <a href="{{ route('segmentation.weekly') }}" class="grid h-10 place-items-center rounded-lg border border-line px-4 text-sm font-medium hover:border-brand-400 hover:text-brand-600">This week</a>
    </form>

    {{-- Week totals --}}
    <div class="mb-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['Assigned', number_format($totals['assigned']), $week['label'], 'from-brand-600 to-brand-700'],
            ['Handled', number_format($totals['handled']), $totals['assigned'] ? round($totals['handled'] / $totals['assigned'] * 100).'% of assigned' : '—', 'from-[#0e8f7c] to-[#0b7d6c]'],
            ['Unprocessed', number_format($totals['unprocessed']), 'from past days this week', 'from-[#e05a5f] to-[#d1494e]'],
            ['Backlog now', number_format($totals['backlog']), 'all unprocessed, any week', 'from-[#7429d6] to-brand-600'],
        ] as [$label, $value, $note, $gradient])
            <div class="relative overflow-hidden rounded-xl bg-gradient-to-br {{ $gradient }} px-4 py-3 text-white shadow-sm">
                <span aria-hidden="true" class="absolute -top-6 -right-6 size-20 rounded-full bg-white/15"></span>
                <span class="relative block text-xs font-medium">{{ $label }}</span>
                <span class="relative mt-0.5 block text-2xl font-bold tabular-nums">{{ $value }}</span>
                <span class="relative block text-[11px] font-medium text-white/90">{{ $note }}</span>
            </div>
        @endforeach
    </div>

    {{-- Per CRA: handled / assigned for each day of the week --}}
    <section class="mb-4 overflow-hidden rounded-xl bg-white shadow-sm" aria-labelledby="weekly-title">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3">
            <h2 id="weekly-title" class="font-semibold">Handled per CRA · {{ $week['label'] }}, {{ $month->format('Y') }}</h2>
            <p class="flex flex-wrap gap-3 text-xs text-muted">
                <span class="flex items-center gap-1"><span class="size-2.5 rounded-sm bg-teal/40"></span>All handled</span>
                <span class="flex items-center gap-1"><span class="size-2.5 rounded-sm bg-[#ffe5a0]"></span>Some unprocessed</span>
                <span class="flex items-center gap-1"><span class="size-2.5 rounded-sm bg-coral/40"></span>None handled</span>
                <span class="flex items-center gap-1"><span class="size-2.5 rounded-sm bg-sky/30"></span>Today</span>
            </p>
        </div>

        @if ($rows->isEmpty())
            <p class="px-4 py-12 text-center text-sm text-muted">No CRAs yet. Give users the CRA role in User Access.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-max min-w-full text-sm">
                    <thead class="bg-canvas/60 text-xs tracking-wide text-muted uppercase">
                        <tr>
                            <th class="sticky left-0 z-10 bg-[#f7f4f8] px-4 py-3 text-left font-semibold">CRA</th>
                            @foreach ($days as $day)
                                <th @class(['px-3 py-3 text-center font-semibold', 'text-brand-600' => $day->equalTo($today)])>
                                    {{ $day->format('D') }}<br><span class="font-normal normal-case">{{ $day->format('M j') }}</span>
                                </th>
                            @endforeach
                            <th class="px-3 py-3 text-right font-semibold">Assigned</th>
                            <th class="px-3 py-3 text-right font-semibold">Handled</th>
                            <th class="px-3 py-3 text-right font-semibold">Unprocessed</th>
                            <th class="px-4 py-3 text-right font-semibold">Handled %</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($rows as $row)
                            <tr>
                                <td class="sticky left-0 z-10 bg-white px-4 py-3">
                                    <span class="flex items-center gap-2 font-medium whitespace-nowrap">
                                        <x-avatar :user="$row['cra']" class="size-7" />
                                        {{ $row['cra']->displayName() }}
                                    </span>
                                </td>
                                @foreach ($row['cells'] as $cell)
                                    <td class="px-1.5 py-2 text-center">
                                        <span @class([
                                            'inline-block min-w-16 rounded-md px-2 py-1.5 tabular-nums',
                                            'bg-teal/20 text-teal-700' => $cell['state'] === 'done',
                                            'bg-[#ffe5a0]/70 text-[#473821]' => $cell['state'] === 'partial',
                                            'bg-coral/20 text-coral-700' => $cell['state'] === 'missed',
                                            'bg-sky/20 text-sky-900' => $cell['state'] === 'today',
                                            'text-muted' => $cell['state'] === 'none',
                                        ]) title="{{ $cell['handled'] }} handled of {{ $cell['assigned'] }} assigned">
                                            {{ $cell['future'] ? '—' : $cell['handled'].' / '.$cell['assigned'] }}
                                        </span>
                                    </td>
                                @endforeach
                                <td class="px-3 py-3 text-right font-semibold tabular-nums">{{ $row['assigned'] }}</td>
                                <td class="px-3 py-3 text-right tabular-nums text-teal-700">{{ $row['handled'] }}</td>
                                <td class="px-3 py-3 text-right tabular-nums {{ $row['unprocessed'] ? 'font-semibold text-coral-700' : 'text-muted' }}">{{ $row['unprocessed'] }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ $row['rate'] === null ? '—' : $row['rate'].'%' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="border-t border-line px-4 py-2 text-xs text-muted">Each day shows handled / assigned. A lead is handled once its status is set; today's open leads count as in progress, not unprocessed.</p>
        @endif
    </section>

    {{-- Carry-over customers this week, per CRA (backlog) --}}
    <section aria-labelledby="unprocessed-title">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <h2 id="unprocessed-title" class="font-semibold">Carry-over customers · {{ $week['label'] }}</h2>
            @if ($canTransfer && $unprocessed->isNotEmpty())
                <button type="button" data-transfer-selected disabled
                        class="h-9 rounded-lg bg-brand-600 px-3 text-sm font-semibold text-white hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-40">
                    Transfer selected
                </button>
            @endif
        </div>

        @forelse ($rows->filter(fn ($r) => isset($unprocessed[$r['cra']->id])) as $row)
            @php($backlog = $unprocessed[$row['cra']->id])
            <div class="mb-4 overflow-hidden rounded-xl bg-white shadow-sm">
                <div class="flex items-center justify-between gap-3 border-b border-line px-4 py-3">
                    <span class="flex items-center gap-2 font-medium">
                        <x-avatar :user="$row['cra']" class="size-7" />
                        {{ $row['cra']->displayName() }}
                        <span class="rounded-full bg-coral/15 px-2 py-0.5 text-xs font-semibold text-coral-700">{{ $backlog->count() }} backlog</span>
                    </span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-left text-sm">
                        <thead class="bg-canvas/60 text-xs tracking-wide text-muted uppercase">
                            <tr>
                                <th class="px-4 py-2.5 font-semibold">Customer</th>
                                <th class="px-4 py-2.5 font-semibold">Product</th>
                                <th class="px-4 py-2.5 font-semibold">Contact #</th>
                                <th class="px-4 py-2.5 font-semibold">Type</th>
                                <th class="px-4 py-2.5 font-semibold">Backlog</th>
                                @if ($canTransfer)
                                    <th class="px-4 py-2.5 text-right font-semibold">Action</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($backlog as $lead)
                                <tr>
                                    <td class="px-4 py-3 font-medium">
                                        <span class="flex items-center gap-2">
                                            @if ($canTransfer)
                                                <input type="checkbox" value="{{ $lead->id }}" data-backlog-select data-from="{{ $lead->assigned_to }}" data-from-name="{{ $row['cra']->displayName() }}"
                                                       aria-label="Select {{ $lead->customer_name }} for transfer" class="size-4 accent-brand-500">
                                            @endif
                                            {{ $lead->customer_name }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">{{ $lead->product_name }}</td>
                                    <td class="px-4 py-3 tabular-nums"><a href="tel:{{ $lead->phone_number }}" class="hover:text-brand-600 hover:underline">{{ $lead->phone_number }}</a></td>
                                    <td class="px-4 py-3">{{ $lead->typeLabel() }}</td>
                                    <td class="px-4 py-3">
                                        <span class="rounded-full bg-coral/15 px-2 py-0.5 text-xs font-semibold whitespace-nowrap text-coral-700">{{ $lead->statusLabel() ?? 'Unprocessed' }} since {{ $lead->est_out_of_stock_date->format('M j') }}</span>
                                        @if ($lead->transfers->first())
                                            <span class="ml-1 text-xs text-muted">from {{ $lead->transfers->first()->fromUser?->displayName() ?? 'another CRA' }}</span>
                                        @endif
                                    </td>
                                    @if ($canTransfer)
                                        <td class="px-4 py-3 text-right">
                                            <button type="button" data-transfer-open data-lead-ids="[{{ $lead->id }}]" data-from="{{ $lead->assigned_to }}"
                                                    data-from-name="{{ $row['cra']->displayName() }}" data-label="{{ $lead->customer_name }}"
                                                    class="rounded-lg border border-line px-3 py-1.5 text-xs font-medium hover:border-brand-400 hover:text-brand-600">Transfer</button>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @empty
            <p class="rounded-xl bg-white px-4 py-10 text-center text-sm text-muted shadow-sm">No carry-over customers this week.</p>
        @endforelse
    </section>

    @if ($canTransfer)
        @include('segmentation._transfer-dialog')
    @endif
</x-layouts.app>
