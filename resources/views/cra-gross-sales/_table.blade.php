{{-- The CRAs' months, TOTAL and the year: re-drawn after a figure is saved (X-Cra-Gross-Sales-Table). --}}
@php
    $name = 'sticky left-0 z-10 min-w-48 border-r border-line bg-white px-4 text-left';
    $yearCell = 'bg-brand-50 font-bold';
@endphp
<div class="overflow-hidden rounded-2xl bg-white shadow-sm">
    <div class="x-scrollbar overflow-x-auto">
        <table class="min-w-full border-separate border-spacing-0 text-[0.8125rem] tabular-nums">
            <thead class="text-[0.6875rem] tracking-wide text-muted uppercase">
                <tr>
                    <th rowspan="2" class="{{ $name }} border-b border-line py-2.5 font-bold">CRA name</th>
                    @foreach ($data['months'] as $month)
                        <th colspan="2" class="border-b border-l border-line px-3 pt-2.5 pb-1 text-center font-bold text-ink">{{ $month->format('M') }}</th>
                    @endforeach
                    <th colspan="2" class="border-b border-l-2 border-line border-l-brand-200 bg-brand-50 px-3 pt-2.5 pb-1 text-center font-bold text-brand-700">{{ $year }}</th>
                    <th rowspan="2" class="border-b border-l-2 border-line border-l-brand-200 px-3 py-2.5 text-right font-bold whitespace-nowrap text-ink" title="(positive + negative Net Income so far) × 1.4%; January–May 2026 only months below ₱250,000">Running ACB</th>
                </tr>
                <tr class="text-[0.625rem]">
                    @foreach ($data['months'] as $month)
                        <th class="border-b border-l border-line px-3 py-1.5 text-right font-semibold">Gross</th>
                        <th class="border-b border-line px-3 py-1.5 text-right font-semibold">Net</th>
                    @endforeach
                    <th class="border-b border-l-2 border-line border-l-brand-200 bg-brand-50 px-3 py-1.5 text-right font-semibold text-brand-700">Gross</th>
                    <th class="border-b border-line bg-brand-50 px-3 py-1.5 text-right font-semibold text-brand-700">Net</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($cras as $cra)
                    @php
                        $row = $data['cras'][$cra->id];
                    @endphp
                    <tr>
                        <td class="{{ $name }} border-b border-line py-2 font-semibold whitespace-nowrap">{{ $cra->displayName() }}</td>
                        @foreach ($row['months'] as $index => $cell)
                            @include('cra-gross-sales._cells', ['cell' => $cell, 'class' => 'border-b border-line', 'first' => 'border-l', 'top' => $data['top'][$index] === $cra->id, 'goal' => $goal,
                                'input' => $canEdit ? ['cra' => $cra->id, 'name' => $cra->displayName(), 'month' => $data['months'][$index], 'typed' => $data['typed'][$cra->id][$index]] : null])
                        @endforeach
                        @include('cra-gross-sales._cells', ['cell' => $row['year'], 'class' => 'border-b border-line '.$yearCell, 'first' => 'border-l-2 border-l-brand-200'])
                        @include('cra-gross-sales._acb', ['value' => $data['acb'][$cra->id], 'class' => 'border-b border-line font-semibold'])
                    </tr>
                @empty
                    <tr><td colspan="28" class="px-4 py-10 text-center text-muted">No active CRAs to show.</td></tr>
                @endforelse
            </tbody>
            <tfoot class="font-bold">
                <tr>
                    <td class="{{ $name }} border-t-2 border-ink py-2">TOTAL</td>
                    @foreach ($data['totals'] as $cell)
                        @include('cra-gross-sales._cells', ['cell' => $cell, 'class' => 'border-t-2 border-ink', 'first' => 'border-l border-l-line'])
                    @endforeach
                    @include('cra-gross-sales._cells', ['cell' => $data['year'], 'class' => 'border-t-2 border-ink '.$yearCell, 'first' => 'border-l-2 border-l-brand-200'])
                    @include('cra-gross-sales._acb', ['value' => $data['acbTotal'], 'class' => 'border-t-2 border-ink'])
                </tr>
            </tfoot>
        </table>
    </div>
</div>
