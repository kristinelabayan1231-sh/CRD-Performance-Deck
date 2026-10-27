{{-- A Customer's Feedback donut with its legend, which doubles as the table view (every choice, its count and share).
     Expects $slices (key => [key, label, value, color]), $total, $centerLabel and $ariaLabel; $flipKey, when given,
     makes that slice and legend row flip the card (data-flip-open). --}}
@php($flipKey = $flipKey ?? null)
<div class="flex h-full flex-col items-center gap-4 rounded-xl bg-white p-4 shadow-sm sm:flex-row sm:items-center">
    <svg viewBox="0 0 100 100" class="size-48 shrink-0 -rotate-90" role="img" aria-label="{{ $ariaLabel }}">
        <circle cx="50" cy="50" r="40" fill="none" stroke="#f2edf3" stroke-width="16" />
        @php($circumference = 2 * M_PI * 40)
        @php($offset = 0)
        @php($drawn = $slices->filter(fn ($s) => $s['value'] > 0))
        @foreach ($drawn as $slice)
            @php($length = $slice['value'] / $total * $circumference)
            {{-- 2px surface gap between slices (none for a single slice) --}}
            <circle cx="50" cy="50" r="40" fill="none" stroke="{{ $slice['color'] }}" stroke-width="16" @class(['transition-opacity hover:opacity-80', 'cursor-pointer' => $slice['key'] === $flipKey]) @if ($slice['key'] === $flipKey) data-flip-open @endif
                    stroke-dasharray="{{ max(0.5, $length - ($drawn->count() > 1 ? 1.2 : 0)) }} {{ $circumference }}" stroke-dashoffset="{{ -$offset }}">
                <title>{{ $slice['label'] }}: {{ number_format($slice['value']) }} ({{ round($slice['value'] / $total * 100, 1) }}%)</title>
            </circle>
            @php($offset += $length)
        @endforeach
        <text x="50" y="47" text-anchor="middle" transform="rotate(90 50 50)" class="fill-ink text-[14px] font-bold">{{ number_format($total) }}</text>
        <text x="50" y="58" text-anchor="middle" transform="rotate(90 50 50)" class="fill-muted text-[6px]">{{ $centerLabel }}</text>
    </svg>
    <table class="w-full text-sm">
        <caption class="sr-only">{{ $ariaLabel }}</caption>
        <tbody>
            @foreach ($slices->sortByDesc('value') as $slice)
                <tr @class(['text-muted' => $slice['value'] === 0])>
                    <td class="py-1 pr-2">
                        <span class="flex items-center gap-2">
                            <span aria-hidden="true" class="size-2.5 shrink-0 rounded-sm" style="background: {{ $slice['color'] }}"></span>
                            @if ($slice['key'] === $flipKey)
                                <button type="button" data-flip-open class="text-left text-xs font-semibold text-brand-700 underline decoration-dotted underline-offset-2 hover:text-brand-600">{{ $slice['label'] }} · why?</button>
                            @else
                                <span class="text-xs">{{ $slice['label'] }}</span>
                            @endif
                        </span>
                    </td>
                    <td class="py-1 text-right text-xs font-semibold tabular-nums">{{ number_format($slice['value']) }}</td>
                    <td class="w-12 py-1 text-right text-xs text-muted tabular-nums">{{ round($slice['value'] / $total * 100) }}%</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
