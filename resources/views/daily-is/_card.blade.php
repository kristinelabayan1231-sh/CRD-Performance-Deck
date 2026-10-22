{{--
    One income statement card. $variant: 'department' (solid purple), 'cra' (light purple) or 'product' (teal), the
    app's panel colours. $f: the statement (DailyIncomeStatement::LINES keys, plus leads, engagements, orders, conversion, aov).
--}}
@php
    $headers = [
        'department' => 'border-brand-700 bg-gradient-to-r from-brand-600 to-brand-700 text-white',
        'cra' => 'border-brand-200 bg-gradient-to-r from-brand-100 to-brand-50 text-ink',
        'product' => 'border-[#b9e8de] bg-gradient-to-r from-[#d3f3ec] to-[#ecfaf6] text-ink',
    ];
    $chips = ['department' => 'bg-white', 'cra' => 'bg-gradient-to-br from-brand-500 to-brand-700', 'product' => 'bg-gradient-to-br from-[#0e8f7c] to-[#0b6b5d]'];
    $money = fn (float $n) => number_format($n, 2);
    $share = fn (float $n) => $f['gross'] > 0 ? number_format($n / $f['gross'] * 100, 2).'%' : '—';
@endphp

<article class="overflow-hidden rounded-2xl border border-line bg-white shadow-sm">
    <header class="flex min-h-10 items-center justify-between gap-2 border-b px-3 py-2 text-[13px] font-bold whitespace-nowrap {{ $headers[$variant] }}">
        <span class="flex min-w-0 items-center gap-2">
            <span aria-hidden="true" class="size-3 shrink-0 rounded {{ $chips[$variant] }}"></span>
            <span class="truncate uppercase" title="{{ $title }}">{{ $title }}</span>
            @if ($deactivated ?? false)
                <span class="shrink-0 rounded-full bg-coral/15 px-2 py-0.5 text-[10px] font-semibold text-coral-700">Deactivated</span>
            @endif
        </span>
        <span @class(['shrink-0 text-xs font-medium', 'text-white/80' => $variant === 'department', 'text-muted' => $variant !== 'department'])>{{ $sub }}</span>
    </header>
    <table class="w-full table-fixed text-[13px] tabular-nums">
        <colgroup><col><col class="w-[92px]"><col class="w-16"></colgroup>
        <tbody>
            @foreach ([['Total number of leads/inquiries', number_format($f['leads'], 2)], ['Total number of engagements', number_format($f['engagements'], 2)], ['Total number of orders', number_format($f['orders'], 2)], ['Overall conversion rate', $f['conversion'] === null ? '—' : number_format($f['conversion'] * 100, 2).'%'], ['Average Order Value', $f['aov'] === null ? '—' : $money($f['aov'])]] as [$label, $value])
                <tr class="font-semibold">
                    <td class="py-1 pr-1.5 pl-3">{{ $label }}</td>
                    <td class="py-1 pr-1.5 text-right">{{ $value }}</td>
                    <td></td>
                </tr>
            @endforeach
            <tr><td colspan="3" class="h-2"></td></tr>
            @foreach ($lines as [$label, $kind, $key])
                @if ($kind === 'section')
                    <tr>
                        <td colspan="3" class="px-3 pt-3 pb-1 text-xs font-bold tracking-wide text-muted uppercase">{{ $label }}</td>
                    </tr>
                    @continue
                @endif
                @php($amount = (float) $f[$key])
                <tr @class([
                    'font-semibold text-ink' => $key === 'gross',
                    'text-[#5f5b6b]' => $kind === 'line' && $key !== 'gross',
                    'border-t-[1.5px] border-ink font-bold' => $kind === 'total',
                    'border-y-[3px] border-double border-ink font-bold' => $kind === 'net',
                    'text-coral-700' => $kind === 'net' && $amount < 0,
                ])>
                    <td class="py-1 pr-1.5 pl-3">{{ $label }}</td>
                    <td class="py-1 pr-1.5 text-right">{{ $money($amount) }}</td>
                    <td class="py-1 pr-3 text-right text-xs">{{ $share($amount) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</article>
