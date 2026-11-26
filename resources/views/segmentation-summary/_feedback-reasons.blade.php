{{-- The back of the Customer's Feedback card for one choice (or the folded Other): why customers gave it, from the CRAs'
     notes. Expects $key, $title, $color and $reasons (total, reasons: [key, label, total], notes). A reason's row shows only
     its notes; the words that matched are highlighted. --}}
@php
    $total = max($reasons['total'], 1);
    $highlight = function (string $note, array $keywords): string {
        $html = e($note);
        foreach (array_unique($keywords) as $keyword) {
            $html = preg_replace('/'.preg_quote(e($keyword), '/').'/iu', '<mark class="rounded-sm bg-amber-100 text-inherit">$0</mark>', $html);
        }

        return $html;
    };
    $labelOf = collect($reasons['reasons'])->pluck('label', 'key');
@endphp
<div data-flip-face="back" data-flip-key="{{ $key }}" hidden inert class="col-start-1 row-start-1 flex rotate-y-180 flex-col gap-3 rounded-xl bg-white p-4 shadow-sm backface-hidden">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="flex items-center gap-2 text-sm font-bold">
            <span aria-hidden="true" class="size-2.5 rounded-sm" style="background: {{ $color }}"></span>
            {{ $title }}
            <span class="rounded-full bg-canvas px-2 py-0.5 text-xs tabular-nums">{{ number_format($reasons['total']) }}</span>
        </h3>
        <button type="button" data-flip-close class="rounded-lg border border-line px-2.5 py-1 text-xs font-semibold hover:border-brand-400 hover:text-brand-600">← Back to chart</button>
    </div>
    <ul class="grid gap-0.5 text-sm" aria-label="Reasons">
        @foreach ($reasons['reasons'] as $reason)
            <li>
                <button type="button" data-reason-filter="{{ $reason['key'] }}" aria-pressed="false" class="grid w-full grid-cols-[45%_1fr_2.5rem_2.5rem] items-center gap-2 rounded-md px-1 py-1 text-left hover:bg-brand-50 aria-pressed:bg-brand-50">
                    <span class="text-xs">{{ $reason['label'] }}</span>
                    <span class="h-2 overflow-hidden rounded-full bg-canvas">
                        <span class="block h-full rounded-full" style="width: {{ round($reason['total'] / $total * 100, 1) }}%; background: {{ $color }}"></span>
                    </span>
                    <span class="text-right text-xs font-semibold tabular-nums">{{ number_format($reason['total']) }}</span>
                    <span class="text-right text-xs text-muted tabular-nums">{{ round($reason['total'] / $total * 100) }}%</span>
                </button>
            </li>
        @endforeach
    </ul>
    <p class="text-[0.6875rem] text-muted" data-reason-hint data-all-text="Click a reason to see only its notes.">Click a reason to see only its notes.</p>
    <ul class="max-h-64 divide-y divide-line overflow-y-auto rounded-lg border border-line text-xs" aria-label="Notes">
        @foreach ($reasons['notes'] as $note)
            <li class="px-3 py-2" data-reasons="{{ implode(' ', $note['reasons']) }}">
                <p class="flex flex-wrap justify-between gap-x-2">
                    <span><span class="font-semibold text-ink">{{ $note['customer'] }}</span> <span class="text-muted">· {{ $note['product'] }}</span></span>
                    <span class="text-muted">{{ collect([$note['cra'], $note['contacted'], $note['status']])->filter()->join(' · ') }}</span>
                </p>
                @if ($note['note'])
                    <p class="mt-0.5">“{!! $highlight($note['note'], $note['keywords']) !!}”</p>
                @elseif (! $note['status'])
                    <p class="mt-0.5 text-muted italic">No note</p>
                @endif
                @if (count($note['reasons']) > 1)
                    <p class="mt-1 flex flex-wrap gap-1">
                        @foreach ($note['reasons'] as $reasonKey)
                            <span class="rounded-full bg-canvas px-2 py-0.5 text-[0.6875rem] text-muted">{{ $labelOf[$reasonKey] ?? $reasonKey }}</span>
                        @endforeach
                    </p>
                @endif
            </li>
        @endforeach
    </ul>
    <p class="text-[0.6875rem] text-muted">
        @if ($key === 'no_verbal_conv')
            Grouped by the words in the CRA's note, e.g. "cx ended the call" → Drop call. Notes that match no group are under Other; leads without a note go by their status.
        @else
            Sorted by the words in the CRA's note, in Tagalog or English, e.g. "may stock pa" → Still has stock. A note can count in more than one reason; notes that match none are under Other.
        @endif
    </p>
</div>
