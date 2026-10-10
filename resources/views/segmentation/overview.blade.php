@php
    $pct = fn (?float $value) => $value === null ? '—' : round($value * 100).'%';
    $field = 'h-9 rounded-lg border border-line bg-white px-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none';
    $feedbackOptions = config('segmentation.feedback');
    $statusOptions = config('segmentation.statuses');
    $tagOptions = config('segmentation.customer_tags');

    // Customer's Feedback colours: one fixed colour per option (never by rank), in the validated
    // categorical order (dataviz palette, light: every adjacent pair passes CVD and normal-vision checks).
    // The rarest option, BLOCKED, folds into a grey "Other" so the donut keeps to eight hues.
    $sliceColors = [
        'still_have_stocks' => '#2a78d6',
        'no_budget' => '#eb6834',
        'currently_using' => '#1baf7a',
        'not_interested' => '#eda100',
        'stopped_by_dr' => '#e87ba4',
        'purchased' => '#008300',
        'no_verbal_conv' => '#6250d6',
        'ineffective' => '#e34948',
    ];
    $feedback = $summary['feedback'];
    $slices = collect($sliceColors)->map(fn (string $color, string $key) => ['label' => $feedbackOptions[$key][0], 'value' => $feedback[$key] ?? 0, 'color' => $color]);
    $other = $feedback->except(array_keys($sliceColors))->sum();
    $slices = $slices->put('other', ['label' => 'Other ('.collect($feedbackOptions)->except(array_keys($sliceColors))->map(fn ($o) => $o[0])->join(', ').')', 'value' => $other, 'color' => '#8a8984']);
    $feedbackTotal = $slices->sum('value');
    $circumference = 2 * M_PI * 40;

    $badge = fn (array $options, ?string $key) => $key && isset($options[$key])
        ? '<span class="rounded-full px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap '.$options[$key][1].'">'.e($options[$key][0]).'</span>'
        : '<span class="text-xs text-muted">—</span>';
@endphp

<x-layouts.app title="Segmentation Summary">
    <h1 class="mb-4 text-xl font-semibold">Segmentation Tracker <span class="text-sm font-normal text-muted">· Summary of the leads, {{ $period }}</span></h1>

    @include('segmentation._tabs')

    {{-- Filters: CRA, type, product on the left; month and day on the right --}}
    <form method="GET" action="{{ route('segmentation.overview') }}" class="mb-4 flex flex-wrap items-end gap-3 rounded-xl bg-white p-4 shadow-sm">
        @if ($canViewAll)
            <label class="text-xs font-medium text-muted">
                <span class="mb-1 block">CRA</span>
                <select name="cra" onchange="this.form.submit()" class="{{ $field }} w-40 text-ink">
                    <option value="all" @selected($filters['cra'] === 'all')>All CRAs</option>
                    @foreach ($cras as $cra)
                        <option value="{{ $cra->id }}" @selected($filters['cra'] === (string) $cra->id)>{{ $cra->displayName() }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        <label class="text-xs font-medium text-muted">
            <span class="mb-1 block">Type</span>
            <select name="type" onchange="this.form.submit()" class="{{ $field }} w-32 text-ink">
                <option value="">All types</option>
                @foreach (\App\Models\Lead::TYPES as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['type'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-xs font-medium text-muted">
            <span class="mb-1 block">Product</span>
            <select name="product" onchange="this.form.submit()" class="{{ $field }} w-36 text-ink">
                <option value="">All products</option>
                @foreach ($products as $product)
                    <option value="{{ $product }}" @selected(($filters['product'] ?? '') === $product)>{{ $product }}</option>
                @endforeach
            </select>
        </label>

        <div class="ml-auto flex flex-wrap items-end gap-3">
            <label class="text-xs font-medium text-muted">
                <span class="mb-1 block">Month</span>
                <input type="month" name="month" value="{{ $filters['month'] }}" onchange="this.form.date.value = ''; this.form.submit()" class="{{ $field }} text-ink">
            </label>
            <label class="text-xs font-medium text-muted">
                <span class="mb-1 block">Or one day</span>
                <input type="date" name="date" value="{{ $filters['date'] ?? '' }}" onchange="this.form.submit()" class="{{ $field }} text-ink">
            </label>
            @if (array_filter(\Illuminate\Support\Arr::except($filters, ['month', 'cra'])) || ($canViewAll && $filters['cra'] !== 'all'))
                <a href="{{ route('segmentation.overview') }}" class="grid h-9 place-items-center rounded-lg border border-line px-3 text-sm font-medium hover:border-brand-400 hover:text-brand-600">Reset</a>
            @endif
        </div>
        <noscript><button type="submit" class="h-9 rounded-lg bg-brand-600 px-3 text-sm font-semibold text-white">Apply</button></noscript>
    </form>

    @if ($summary['leads'] === 0)
        <p class="rounded-xl bg-white px-4 py-12 text-center text-sm text-muted shadow-sm">No leads for these filters.</p>
    @else
        {{-- Headline numbers --}}
        <section aria-label="Totals" class="mb-4 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
            @foreach ([
                ['Leads', number_format($summary['leads']), $period, 'from-brand-500 to-brand-700'],
                ['Catered', $pct($summary['catered_rate']), number_format($summary['catered']).' leads', 'from-[#0e8f7c] to-[#0b6b5d]'],
                ['Pending', number_format($summary['pending']), 'no status or contact yet', 'from-[#e05a5f] to-[#c4484c]'],
                ['Converted', $pct($summary['converted_rate']), number_format($summary['converted']).' leads', 'from-[#1f8fb8] to-[#156c8c]'],
                ['Feedback recorded', $pct($summary['feedback_rate']), number_format($summary['with_feedback']).' of '.number_format($summary['catered']).' catered', 'from-[#d99a0b] to-[#a8740a]'],
            ] as [$label, $value, $note, $gradient])
                <div class="relative overflow-hidden rounded-xl bg-gradient-to-br {{ $gradient }} p-4 text-white shadow-sm">
                    <span aria-hidden="true" class="absolute -top-6 -right-6 size-20 rounded-full bg-white/15"></span>
                    <p class="relative text-[11px] font-semibold tracking-wide uppercase">{{ $label }}</p>
                    <p class="relative text-3xl font-bold tabular-nums">{{ $value }}</p>
                    <p class="relative text-xs text-white/90">{{ $note }}</p>
                </div>
            @endforeach
        </section>

        <div class="grid items-start gap-4 xl:grid-cols-12">
            {{-- Customer's Feedback donut --}}
            <x-panel title="Customer's feedback" icon="pie" class="xl:col-span-5" body-class="p-4">
                <x-slot:badges>
                    <span class="rounded-full bg-white px-2 py-0.5 text-xs font-bold text-brand-700 tabular-nums shadow-sm">{{ number_format($feedbackTotal) }}</span>
                </x-slot:badges>
                @if ($feedbackTotal === 0)
                    <p class="rounded-xl bg-white px-4 py-10 text-center text-sm text-muted shadow-sm">No Customer's Feedback recorded yet.</p>
                @else
                    <div class="flex flex-col items-center gap-4 rounded-xl bg-white p-4 shadow-sm sm:flex-row sm:items-start">
                        <svg viewBox="0 0 100 100" class="size-48 shrink-0 -rotate-90" role="img" aria-label="Customer's feedback, {{ $period }}">
                            <circle cx="50" cy="50" r="40" fill="none" stroke="#f2edf3" stroke-width="16" />
                            @php($offset = 0)
                            @php($drawn = $slices->filter(fn ($s) => $s['value'] > 0))
                            @foreach ($drawn as $slice)
                                @php($length = $slice['value'] / $feedbackTotal * $circumference)
                                {{-- 2px surface gap between slices (none for a single slice) --}}
                                <circle cx="50" cy="50" r="40" fill="none" stroke="{{ $slice['color'] }}" stroke-width="16" class="transition-opacity hover:opacity-80"
                                        stroke-dasharray="{{ max(0.5, $length - ($drawn->count() > 1 ? 1.2 : 0)) }} {{ $circumference }}" stroke-dashoffset="{{ -$offset }}">
                                    <title>{{ $slice['label'] }}: {{ number_format($slice['value']) }} ({{ round($slice['value'] / $feedbackTotal * 100, 1) }}%)</title>
                                </circle>
                                @php($offset += $length)
                            @endforeach
                            <text x="50" y="47" text-anchor="middle" transform="rotate(90 50 50)" class="fill-ink text-[14px] font-bold">{{ number_format($feedbackTotal) }}</text>
                            <text x="50" y="58" text-anchor="middle" transform="rotate(90 50 50)" class="fill-muted text-[6px]">with feedback</text>
                        </svg>
                        {{-- Legend doubles as the table view: every option, its count and share --}}
                        <table class="w-full text-sm">
                            <caption class="sr-only">Customer's feedback counts</caption>
                            <tbody>
                                @foreach ($slices->sortByDesc('value') as $slice)
                                    <tr @class(['text-muted' => $slice['value'] === 0])>
                                        <td class="py-1 pr-2">
                                            <span class="flex items-center gap-2">
                                                <span aria-hidden="true" class="size-2.5 shrink-0 rounded-sm" style="background: {{ $slice['color'] }}"></span>
                                                <span class="text-xs">{{ $slice['label'] }}</span>
                                            </span>
                                        </td>
                                        <td class="py-1 text-right text-xs font-semibold tabular-nums">{{ number_format($slice['value']) }}</td>
                                        <td class="w-12 py-1 text-right text-xs text-muted tabular-nums">{{ round($slice['value'] / $feedbackTotal * 100) }}%</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="mt-2 text-xs text-muted">{{ number_format($summary['catered'] - $summary['with_feedback']) }} catered {{ Str::plural('lead', $summary['catered'] - $summary['with_feedback']) }} have no feedback yet and aren't in the chart.</p>
                @endif
            </x-panel>

            {{-- Insights --}}
            <x-panel title="Insights" icon="bulb" accent="amber" class="xl:col-span-7" body-class="p-4">
                @if (empty($summary['insights']))
                    <p class="rounded-xl bg-white px-4 py-6 text-center text-sm text-muted shadow-sm">Not enough activity yet for insights.</p>
                @else
                    <ul class="space-y-2">
                        @foreach ($summary['insights'] as $insight)
                            <li class="flex items-start gap-3 rounded-xl bg-white p-3 text-sm shadow-sm">
                                {{-- Tone shown by icon and colour together, never colour alone --}}
                                <span @class([
                                    'grid size-6 shrink-0 place-items-center rounded-full text-xs font-bold',
                                    'bg-teal/15 text-teal-700' => $insight['tone'] === 'good',
                                    'bg-coral/20 text-coral-700' => $insight['tone'] === 'warning',
                                    'bg-sky/20 text-[#156c8c]' => $insight['tone'] === 'info',
                                ]) aria-label="{{ ['good' => 'Good', 'warning' => 'Needs attention', 'info' => 'Note'][$insight['tone']] }}">{{ ['good' => '✓', 'warning' => '!', 'info' => 'i'][$insight['tone']] }}</span>
                                <span>{{ $insight['text'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-panel>

            {{-- Statuses and customer tags: one hue each, labels carry identity --}}
            @foreach ([
                ['Statuses', 'check', 'teal', $summary['statuses'], $statusOptions, '#0e8f7c', 'leads with a status'],
                ['Customer tags', 'tag', 'sky', $summary['tags'], $tagOptions, '#2a78d6', 'tagged leads'],
            ] as [$title, $icon, $accent, $counts, $options, $barColor, $noun])
                @php($max = max(1, $counts->max() ?? 0))
                <x-panel :title="$title" :icon="$icon" :accent="$accent" class="xl:col-span-6" body-class="p-4">
                    <x-slot:badges>
                        <span class="text-xs text-muted">{{ number_format($counts->sum()) }} {{ $noun }}</span>
                    </x-slot:badges>
                    <ul class="space-y-2 rounded-xl bg-white p-4 shadow-sm">
                        @foreach ($options as $key => [$label])
                            @php($value = $counts[$key] ?? 0)
                            <li class="grid grid-cols-[minmax(0,13rem)_1fr_3rem] items-center gap-3 text-sm" data-tip="{{ $label }}: {{ number_format($value) }}">
                                <span class="truncate text-xs" title="{{ $label }}">{!! $badge($options, $key) !!}</span>
                                <span class="block h-2.5 rounded-r bg-canvas">
                                    <span class="block h-full rounded-r" style="width: {{ $value / $max * 100 }}%; background: {{ $barColor }}; min-width: {{ $value ? '4px' : '0' }}"></span>
                                </span>
                                <span class="text-right text-xs font-semibold tabular-nums">{{ number_format($value) }}</span>
                            </li>
                        @endforeach
                    </ul>
                </x-panel>
            @endforeach

            {{-- Contact hours: contacts per hour, the converted part darker (same axis) --}}
            @php($hours = $summary['hours'])
            @php($peak = max(1, $hours->max('contacts')))
            <x-panel title="Time of contact" icon="clock" class="xl:col-span-12" body-class="p-4">
                <x-slot:actions>
                    <span class="flex gap-3 text-muted">
                        <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-[#86b6ef]"></span>Contacted</span>
                        <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-[#1c5cab]"></span>Converted</span>
                    </span>
                </x-slot:actions>
                <div class="overflow-x-auto rounded-xl bg-white p-4 shadow-sm">
                    <div class="flex h-48 min-w-[720px] items-end gap-2 border-b border-line" role="img" aria-label="Leads contacted and converted per hour, {{ $period }}">
                        @foreach ($hours as $hour)
                            <div class="group relative flex h-full flex-1 flex-col justify-end" data-tip="{{ $hour['label'] }}: {{ number_format($hour['contacts']) }} contacted, {{ number_format($hour['converted']) }} converted">
                                @if ($hour['contacts'] > 0)
                                    <span class="mb-1 text-center text-[10px] font-semibold text-muted tabular-nums">{{ $hour['contacts'] }}</span>
                                @endif
                                <span class="relative block rounded-t bg-[#86b6ef] group-hover:bg-[#6da7ec]" style="height: {{ $hour['contacts'] / $peak * 85 }}%; min-height: {{ $hour['contacts'] ? '4px' : '0' }}">
                                    <span class="absolute inset-x-0 bottom-0 block bg-[#1c5cab]" style="height: {{ $hour['contacts'] ? $hour['converted'] / $hour['contacts'] * 100 : 0 }}%"></span>
                                </span>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-1.5 flex min-w-[720px] gap-2">
                        @foreach ($hours as $hour)
                            <span class="flex-1 text-center text-[10px] text-muted">{{ \Illuminate\Support\Str::before($hour['label'], '-') }}</span>
                        @endforeach
                    </div>
                </div>
            </x-panel>

            {{-- Per CRA and per product --}}
            @foreach ([
                ['Per CRA', 'users', $summary['cras'], 'CRA'],
                ['Per product', 'box', $summary['products'], 'Product'],
            ] as [$title, $icon, $rows, $first])
                <x-panel :title="$title" :icon="$icon" class="xl:col-span-6" :tinted="false" body-class="">
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[520px] text-sm">
                            <thead class="bg-[#f7f4f8] text-[11px] tracking-wide text-muted uppercase">
                                <tr>
                                    <th class="px-4 py-2.5 text-left font-semibold">{{ $first }}</th>
                                    <th class="px-3 py-2.5 text-right font-semibold">Leads</th>
                                    <th class="px-3 py-2.5 text-right font-semibold">Catered</th>
                                    <th class="px-3 py-2.5 text-right font-semibold">Converted</th>
                                    <th class="px-4 py-2.5 text-left font-semibold">Top feedback</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line tabular-nums">
                                @forelse ($rows as $row)
                                    <tr>
                                        <td class="px-4 py-2.5 font-medium">{{ $row['name'] }}</td>
                                        <td class="px-3 py-2.5 text-right">{{ number_format($row['leads']) }}</td>
                                        <td class="px-3 py-2.5 text-right">{{ $pct($row['catered_rate']) }}</td>
                                        <td class="px-3 py-2.5 text-right">{{ number_format($row['converted']) }} <span class="text-xs text-muted">({{ $pct($row['converted_rate']) }})</span></td>
                                        <td class="px-4 py-2.5">{!! $badge($feedbackOptions, $row['top_feedback']) !!}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="px-4 py-6 text-center text-muted">Nothing to show.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-panel>
            @endforeach
        </div>

        <details class="mt-4 rounded-xl bg-white p-4 text-sm shadow-sm">
            <summary class="cursor-pointer font-semibold">How the numbers are worked out</summary>
            <dl class="mt-3 grid gap-x-6 gap-y-2 text-muted sm:grid-cols-2">
                <div><dt class="font-semibold text-ink">Leads</dt><dd>Segmentation Tracker leads whose lead day (est. out of stock) is in the month picked, up to today's lead day, or the one day picked.</dd></div>
                <div><dt class="font-semibold text-ink">Catered / Pending</dt><dd>Catered = a status, a date of contact, or marked catered. Pending = none of these yet.</dd></div>
                <div><dt class="font-semibold text-ink">Converted</dt><dd>Repeat Purchase Yes, or Repeat Purchase No with feedback PURCHASED.</dd></div>
                <div><dt class="font-semibold text-ink">Customer's feedback</dt><dd>Leads with Customer's Feedback filled in. BLOCKED is shown under Other. Leads without feedback aren't in the chart.</dd></div>
                <div><dt class="font-semibold text-ink">Time of contact</dt><dd>Leads by the Time of Contact picked; the dark part of each bar is how many of them converted.</dd></div>
                <div><dt class="font-semibold text-ink">Insights</dt><dd>Worked out from these numbers. Hours, products and CRAs are only compared once they have at least {{ \App\Services\SegmentationSummary::MIN_FOR_INSIGHT }} leads.</dd></div>
            </dl>
        </details>
    @endif
</x-layouts.app>
