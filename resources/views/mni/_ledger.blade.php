{{--
    The MNI statement: line names and the department pinned on the left, a column per CRA. Re-drawn after each input
    is saved (X-Mni-Ledger). Money lines show their % of that column's Gross Sales underneath.
--}}
@php
    $columns = collect([['label' => 'Department', 'sub' => 'All CRAs', 'figures' => $data['department'], 'department' => true]])
        ->concat($cras->map(fn ($cra) => ['label' => $cra->displayName(), 'sub' => null, 'figures' => $data['cras'][$cra->id], 'department' => false]));
    $format = function (?float $value, string $kind): string {
        if ($value === null) {
            return '—';
        }

        return match ($kind) {
            'count' => number_format($value, 2),
            'percent' => number_format($value * 100, 2).'%',
            default => number_format($value, 2),
        };
    };
    $label = 'sticky left-0 z-10 min-w-56 border-r border-line bg-white px-4 text-left';
    $fixed = 'sticky left-56 z-10 min-w-36 border-r-2 border-brand-200 bg-brand-50 px-3 text-right font-bold';
@endphp

<table class="min-w-full border-separate border-spacing-0 text-sm tabular-nums" data-mni-ledger>
    <thead class="text-xs tracking-wide uppercase">
        <tr>
            <th class="{{ $label }} bg-canvas py-2.5 font-semibold text-muted">Line</th>
            <th class="{{ $fixed }} bg-gradient-to-r from-brand-600 to-brand-700 py-2.5 text-white">Department</th>
            @foreach ($cras as $cra)
                <th class="min-w-36 bg-[#ecfaf6] px-3 py-2.5 text-right font-semibold whitespace-nowrap text-[#0b6b5d]">{{ $cra->displayName() }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach ($lines as [$name, $key, $kind])
            @if ($kind === 'section')
                <tr>
                    <td class="{{ $label }} border-y border-line bg-[#faf7fb] py-2 text-xs font-bold tracking-wide text-muted uppercase">
                        {{ $name }}
                    </td>
                    <td class="{{ $fixed }} border-y border-line bg-[#faf7fb]"></td>
                    @foreach ($cras as $cra)
                        <td class="border-y border-line bg-[#faf7fb]"></td>
                    @endforeach
                </tr>
                @continue
            @endif
            @php($emphasis = in_array($kind, ['total', 'net'], true))
            <tr>
                <td @class([$label, 'border-b border-line py-1.5', 'border-t-[1.5px] border-t-ink font-bold' => $kind === 'total', 'border-y-[3px] border-double border-ink font-bold text-ink' => $kind === 'net'])>{{ $name }}</td>
                @foreach ($columns as $column)
                    @php($value = $key ? ($column['figures'][$key] ?? null) : null)
                    <td @class([
                        $column['department'] ? $fixed : 'px-3 text-right',
                        'border-b border-line py-1.5 whitespace-nowrap',
                        'border-t-[1.5px] border-t-ink font-bold' => $kind === 'total',
                        'border-y-[3px] border-double border-ink font-bold' => $kind === 'net',
                        'text-coral-700' => $kind === 'net' && $value < 0,
                    ])>
                        {{ $format($value, $kind) }}
                        @if (in_array($kind, ['money', 'total', 'net'], true) && ! in_array($key, ['aov'], true) && $column['figures']['gross'] > 0)
                            <span class="block text-[0.6875rem] font-normal text-muted">{{ number_format($value / $column['figures']['gross'] * 100, 2) }}%</span>
                        @endif
                    </td>
                @endforeach
            </tr>
        @endforeach
    </tbody>
</table>
