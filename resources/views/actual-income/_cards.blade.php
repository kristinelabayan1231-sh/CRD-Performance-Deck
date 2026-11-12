@use('App\Services\ActualIncome')
{{--
    The CRD card and a card per CRA, laid out like the CRD sheet. Re-drawn after each amount is saved
    (X-Actual-Income-Cards). Each value is tinted by where it comes from: typed in, the CRA cards added up, or the CRD
    card shared out. Money lines show their % of the card's Gross Sales.
--}}
@php
    $cards = collect([['title' => 'Customer Retention Department', 'sub' => 'All CRAs', 'cra' => null, 'figures' => $data['department'], 'typed' => $data['typed']['department'], 'department' => true]])
        ->concat($cras->map(fn ($cra) => ['title' => $cra->displayName(), 'sub' => 'CRA', 'cra' => $cra->id, 'figures' => $data['cras'][$cra->id], 'typed' => $data['typed']['cras'][$cra->id] ?? [], 'department' => false]));
    $tints = [
        'input' => 'bg-[#fff1e3]',
        'sum' => 'bg-[#e3f6fb]',
        'share' => 'bg-[#e8f5e8]',
        'research' => 'bg-[#e8f5e8]',
    ];
    $box = 'h-7 w-full rounded-md border border-[#f1c89b] bg-[#fff1e3] px-1.5 text-right text-[0.8125rem] font-semibold tabular-nums placeholder:font-normal placeholder:text-muted/60 focus:border-brand-400 focus:bg-white focus:ring-2 focus:ring-brand-200 focus:outline-none';
@endphp

<div class="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(340px,1fr))]">
    @foreach ($cards as $card)
        @php
            $f = $card['figures'];
        @endphp
        <article class="overflow-hidden rounded-2xl border border-line bg-white shadow-sm" aria-label="{{ $card['title'] }}">
            <header @class([
                'flex min-h-10 items-center justify-between gap-2 border-b px-3 py-2 text-[0.8125rem] font-bold whitespace-nowrap',
                'border-brand-700 bg-gradient-to-r from-brand-600 to-brand-700 text-white' => $card['department'],
                'border-brand-200 bg-gradient-to-r from-brand-100 to-brand-50 text-ink' => ! $card['department'],
            ])>
                <span class="flex min-w-0 items-center gap-2">
                    <span aria-hidden="true" @class(['size-3 shrink-0 rounded', 'bg-white' => $card['department'], 'bg-gradient-to-br from-brand-500 to-brand-700' => ! $card['department']])></span>
                    <span class="truncate uppercase" title="{{ $card['title'] }}">{{ $card['title'] }}</span>
                </span>
                <span @class(['shrink-0 text-xs font-medium', 'text-white/80' => $card['department'], 'text-muted' => ! $card['department']])>{{ $month->format('M Y') }}</span>
            </header>
            <table class="w-full table-fixed text-[0.8125rem] tabular-nums">
                <colgroup><col><col class="w-[7.5rem]"><col class="w-16"></colgroup>
                <tbody>
                    @foreach (ActualIncome::LINES as [$label, $key, $kind, $crdSource, $craSource])
                        @if ($kind === 'section')
                            <tr>
                                <td colspan="3" class="truncate px-3 pt-3 pb-1 text-xs font-bold tracking-wide text-muted uppercase">{{ $label }}</td>
                            </tr>
                            @continue
                        @endif
                        @php
                            $source = $card['department'] ? $crdSource : $craSource;
                            $value = $f[$key];
                            $shown = match (true) {
                                $value === null => '—',
                                $kind === 'percent' => number_format($value * 100, 2).'%',
                                default => number_format($value, 2),
                            };
                            $isKpi = in_array($key, ['target_leads', 'orders', 'conversion', 'aov'], true);
                        @endphp
                        <tr @class([
                            'font-semibold text-ink' => $isKpi || $key === 'gross',
                            'text-[#5f5b6b]' => $kind === 'money' && ! $isKpi && $key !== 'gross',
                            'border-t-[1.5px] border-ink font-bold' => $kind === 'total',
                            'border-y-[3px] border-double border-ink font-bold' => $kind === 'net',
                            'text-coral-700' => $kind === 'net' && $value < 0,
                        ])>
                            <td class="truncate py-0.5 pr-1.5 pl-3" title="{{ $label }}">{{ $label }}</td>
                            @if ($source === 'input' && $canEdit)
                                <td class="py-0.5 pr-1.5">
                                    <input type="text" inputmode="decimal" data-actual-income-input data-cra="{{ $card['cra'] }}" data-field="{{ $key }}"
                                           value="{{ isset($card['typed'][$key]) ? number_format($card['typed'][$key], 2) : '' }}" placeholder="0.00"
                                           aria-label="{{ $label }} for {{ $card['title'] }}" class="{{ $box }}">
                                </td>
                            @else
                                <td class="py-0.5 pr-1.5 text-right"><span class="block rounded-md px-1.5 py-0.5 {{ $tints[$source] ?? '' }}">{{ $shown }}</span></td>
                            @endif
                            <td class="py-0.5 pr-3 text-right text-xs">
                                @if (! $isKpi && $f['gross'] > 0)
                                    {{ number_format($value / $f['gross'] * 100, 2) }}%
                                @endif
                            </td>
                        </tr>
                        @if ($key === 'aov')
                            <tr><td colspan="3" class="h-2"></td></tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </article>
    @endforeach
</div>
