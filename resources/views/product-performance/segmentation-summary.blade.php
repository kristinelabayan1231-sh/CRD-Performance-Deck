@php
    $pct = fn (?float $value) => $value === null ? '—' : round($value * 100).'%';
    $field = 'h-9 rounded-lg border border-line bg-white px-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none';
    $feedbackOptions = config('segmentation.feedback');
    $statusOptions = config('segmentation.statuses');
    $tagOptions = config('segmentation.customer_tags');

    // Feedback colours: one fixed colour per choice (never by rank), in the validated categorical order (dataviz
    // palette, light: every adjacent pair passes CVD and normal-vision checks), shared by the donut and the per-product
    // bars. The rarest choice, BLOCKED, and any choice added later fold into a grey "Other" so the donut keeps to eight hues.
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
    $fold = fn (string $key) => isset($sliceColors[$key]) ? $key : 'other';
    $otherLabel = 'Other ('.collect($feedbackOptions)->except(array_keys($sliceColors))->map(fn ($o) => $o[0])->join(', ').')';

    // Every choice ticked, so a lead with two counts in both.
    $picks = $summary['feedback_picks'];
    $pickSlices = collect($sliceColors)->map(fn (string $color, string $key) => ['key' => $key, 'label' => $feedbackOptions[$key][0] ?? $key, 'value' => $picks[$key] ?? 0, 'color' => $color])
        ->put('other', ['key' => 'other', 'label' => $otherLabel, 'value' => $picks->except(array_keys($sliceColors))->sum(), 'color' => '#8a8984']);
    $pickTotal = $pickSlices->sum('value');
    // Every choice but PURCHASED flips the card to why customers gave it (Other: the folded choices together).
    $reasonsByChoice = $summary['feedback_reasons'];
    $folded = collect($reasonsByChoice)->except(array_keys($sliceColors));
    if ($folded->isNotEmpty()) {
        $reasonsByChoice['other'] = [
            'total' => $folded->sum('total'),
            'reasons' => $folded->flatMap(fn (array $choice) => $choice['reasons'])->groupBy('key')
                ->map(fn ($rows, $key) => ['key' => $key, 'label' => $rows->first()['label'], 'total' => $rows->sum('total')])
                ->sortByDesc('total')->values()->all(),
            'notes' => $folded->flatMap(fn (array $choice) => $choice['notes'])->all(),
        ];
    }
    $flipKeys = $pickSlices->keys()->filter(fn (string $key) => $key !== 'purchased' && ! empty($reasonsByChoice[$key]['notes']))->values()->all();

    $badge = fn (array $options, ?string $key) => $key && isset($options[$key])
        ? '<span class="rounded-full px-2 py-0.5 text-[0.6875rem] font-semibold whitespace-nowrap '.$options[$key][1].'">'.e($options[$key][0]).'</span>'
        : '<span class="text-xs text-muted">—</span>';
@endphp

<x-layouts.app title="Segmentation Summary">
    <div class="mb-4">@include('product-performance._tabs')</div>

    <h1 class="mb-4 text-xl font-semibold">Product Performance <span class="text-sm font-normal text-muted">· Segmentation Tracker leads, {{ $period }}@if ($leadDays) <span title="The leads handled on these days come from these lead days (est. out of stock).">(lead days {{ $leadDays }})</span>@endif</span></h1>

    {{-- Filters: CRA, type, product on the left; month and day on the right --}}
    <form method="GET" action="{{ route('product-performance.segmentation-summary') }}" class="mb-4 flex flex-wrap items-end gap-3 rounded-xl bg-white p-4 shadow-sm">
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
                <a href="{{ route('product-performance.segmentation-summary') }}" class="grid h-9 place-items-center rounded-lg border border-line px-3 text-sm font-medium hover:border-brand-400 hover:text-brand-600">Reset</a>
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
                    <p class="relative text-[0.6875rem] font-semibold tracking-wide uppercase">{{ $label }}</p>
                    <p class="relative text-3xl font-bold tabular-nums">{{ $value }}</p>
                    <p class="relative text-xs text-white/90">{{ $note }}</p>
                </div>
            @endforeach
        </section>

        <div class="grid items-start gap-4 xl:grid-cols-12">
            {{-- Customer's Feedback: every choice ticked, so a lead with two counts twice; by product beside it --}}
            <x-panel title="Customer's Feedback" icon="pie" class="xl:col-span-12" body-class="p-4">
                <x-slot:badges>
                    <span class="rounded-full bg-white px-2 py-0.5 text-xs font-bold text-brand-700 tabular-nums shadow-sm">{{ number_format($pickTotal) }}</span>
                </x-slot:badges>
                @if ($pickTotal === 0)
                    <p class="rounded-xl bg-white px-4 py-10 text-center text-sm text-muted shadow-sm">No Customer's Feedback recorded yet.</p>
                @else
                    {{-- Donut and By product side by side at the same height; By product scrolls when longer --}}
                    <div class="grid gap-4 lg:grid-cols-2">
                    {{-- A flip card: the donut in front; a choice's slice or legend row turns it to why customers gave it --}}
                    <div class="grid perspective-[1200px]">
                    <div data-flip-inner class="grid transition-transform duration-500 transform-3d motion-reduce:transition-none data-flipped:rotate-y-180">
                    <div data-flip-face="front" class="col-start-1 row-start-1 backface-hidden">
                    @include('product-performance._feedback-donut', ['slices' => $pickSlices, 'total' => $pickTotal, 'centerLabel' => 'choices ticked',
                        'ariaLabel' => "Customer's feedback, every choice ticked, {$period}", 'flipKeys' => $flipKeys])
                    </div>
                    @foreach ($flipKeys as $flipKey)
                        @include('product-performance._feedback-reasons', [
                            'key' => $flipKey,
                            'title' => $flipKey === 'no_verbal_conv' ? 'Why no verbal conversation' : 'Why customers said '.($flipKey === 'other' ? 'Other' : $pickSlices[$flipKey]['label']),
                            'color' => $pickSlices[$flipKey]['color'],
                            'reasons' => $reasonsByChoice[$flipKey],
                        ])
                    @endforeach
                    </div>
                    </div>
                    <div class="relative min-h-48">
                        <div class="lg:absolute lg:inset-0">
                            @include('product-performance._feedback-by-product', ['byProduct' => $summary['feedback_picks_by_product'], 'slices' => $pickSlices, 'noun' => 'choices'])
                        </div>
                    </div>
                    </div>
                    <p class="mt-2 text-xs text-muted">Every choice ticked on {{ number_format($summary['with_feedback']) }} {{ Str::plural('lead', $summary['with_feedback']) }} (a lead with several counts once for each). {{ number_format($summary['catered'] - $summary['with_feedback']) }} catered {{ Str::plural('lead', $summary['catered'] - $summary['with_feedback']) }} have no feedback yet and aren't in the chart.</p>

                    {{-- Per product: click one for its customers and Qty per choice ticked (like Order Database → CRA's Performance) --}}
                    <div class="mt-4 overflow-hidden rounded-xl bg-white shadow-sm">
                        <h3 class="px-4 pt-4 pb-2 text-xs font-bold tracking-wide text-muted uppercase">Feedback by product <span class="font-normal tracking-normal normal-case">· click a product for its customers, shipments and Qty per choice</span></h3>
                        <div class="x-scrollbar overflow-x-auto">
                            <table class="w-full min-w-[32.5rem] text-sm tabular-nums">
                                <thead class="bg-canvas text-[0.6875rem] font-semibold tracking-wide text-muted uppercase">
                                    <tr>
                                        <th scope="col" class="px-4 py-3 text-left">Product / Reason</th>
                                        <th scope="col" class="px-4 py-3 text-right whitespace-nowrap"># of Customers</th>
                                        <th scope="col" class="px-4 py-3 text-right">Shipments</th>
                                        <th scope="col" class="px-4 py-3 text-right">Qty</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($summary['feedback_reasons_by_product'] as $product => $row)
                                        @php($rowId = 'feedback-product-'.$loop->index)
                                        <tr class="border-t border-line">
                                            <th scope="row" class="px-4 py-2.5 text-left">
                                                <button type="button" aria-expanded="false" aria-controls="{{ $rowId }}"
                                                        onclick="const open = this.getAttribute('aria-expanded') !== 'true'; this.setAttribute('aria-expanded', open); document.querySelectorAll('[data-feedback-product=&quot;{{ $loop->index }}&quot;]').forEach((row) => row.hidden = ! open)"
                                                        class="group inline-flex items-center gap-1.5 font-semibold text-brand-600 hover:underline">
                                                    <svg class="size-3.5 transition group-aria-expanded:rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 6 6 6-6 6"/></svg>
                                                    {{ $product ?: '—' }}
                                                </button>
                                            </th>
                                            <td class="px-4 py-2.5 text-right font-mono">{{ number_format($row['customers']) }}</td>
                                            <td class="px-4 py-2.5 text-right font-mono">{{ number_format($row['shipments']) }}</td>
                                            <td class="px-4 py-2.5 text-right font-mono">{{ number_format($row['qty']) }}</td>
                                        </tr>
                                        @foreach ($row['reasons'] as $reason => $counts)
                                            <tr data-feedback-product="{{ $loop->parent->index }}" @if ($loop->first) id="{{ $rowId }}" @endif hidden class="border-t border-brand-100 bg-brand-50/60 text-[0.8125rem]">
                                                <th scope="row" class="py-2 pr-4 pl-10 text-left font-medium">{!! $badge($feedbackOptions, $reason) !!}</th>
                                                <td class="px-4 py-2 text-right font-mono">{{ number_format($counts['customers']) }}</td>
                                                <td class="px-4 py-2 text-right font-mono">{{ number_format($counts['shipments']) }}</td>
                                                <td class="px-4 py-2 text-right font-mono">{{ number_format($counts['qty']) }}</td>
                                            </tr>
                                        @endforeach
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
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
                    <div class="flex h-48 min-w-[45rem] items-end gap-2 border-b border-line" role="img" aria-label="Leads contacted and converted per hour, {{ $period }}">
                        @foreach ($hours as $hour)
                            <div class="group relative flex h-full flex-1 flex-col justify-end" data-tip="{{ $hour['label'] }}: {{ number_format($hour['contacts']) }} contacted, {{ number_format($hour['converted']) }} converted">
                                @if ($hour['contacts'] > 0)
                                    <span class="mb-1 text-center text-[0.625rem] font-semibold text-muted tabular-nums">{{ $hour['contacts'] }}</span>
                                @endif
                                <span class="relative block rounded-t bg-[#86b6ef] group-hover:bg-[#6da7ec]" style="height: {{ $hour['contacts'] / $peak * 85 }}%; min-height: {{ $hour['contacts'] ? '4px' : '0' }}">
                                    <span class="absolute inset-x-0 bottom-0 block bg-[#1c5cab]" style="height: {{ $hour['contacts'] ? $hour['converted'] / $hour['contacts'] * 100 : 0 }}%"></span>
                                </span>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-1.5 flex min-w-[45rem] gap-2">
                        @foreach ($hours as $hour)
                            <span class="flex-1 text-center text-[0.625rem] text-muted">{{ \Illuminate\Support\Str::before($hour['label'], '-') }}</span>
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
                        <table class="w-full min-w-[32.5rem] text-sm">
                            <thead class="bg-[#f7f4f8] text-[0.6875rem] tracking-wide text-muted uppercase">
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
                <div><dt class="font-semibold text-ink">Leads</dt><dd>Segmentation Tracker leads handled in the month picked (up to today), or on the one day picked: the leads of the lead days (est. out of stock) paired with those days in Settings → Working Date.</dd></div>
                <div><dt class="font-semibold text-ink">Catered / Pending</dt><dd>Catered = a status, a date of contact, or marked catered. Pending = none of these yet.</dd></div>
                <div><dt class="font-semibold text-ink">Converted</dt><dd>Repeat Purchase Yes, or Repeat Purchase No with feedback PURCHASED.</dd></div>
                <div><dt class="font-semibold text-ink">Customer's Feedback</dt><dd>Every Customer's Feedback choice ticked, so a lead with two choices counts in both; By product splits them by the lead's product. BLOCKED is shown under Other. Leads without feedback aren't in the chart. Click a choice (its slice or legend row) to flip the card to why customers gave it: the notes of its leads sorted into reasons by their words, in Tagalog or English (e.g. Still has stock, No budget, No improvement seen, Someone else answered), with every note; click a reason for only its notes. A note can count in more than one reason. NO VERBAL CONV puts each lead in one group (PJR, CBR, Drop call, Busy, Unattended, Invalid / wrong number); a lead without a note goes by its status.</dd></div>
                <div><dt class="font-semibold text-ink">Feedback by product</dt><dd>Per product, the customers (by contact number) with Customer's Feedback, their shipments (leads: each is one delivered order) and Qty (the pieces in those orders); open a product for the same per choice ticked. A customer with two choices is under both, so the choices can add up to more than the product.</dd></div>
                <div><dt class="font-semibold text-ink">Top feedback</dt><dd>The most common main choice of the CRA's or product's leads: PURCHASED when it's ticked, else the first one ticked.</dd></div>
                <div><dt class="font-semibold text-ink">Time of contact</dt><dd>Leads by the Time of Contact picked; the dark part of each bar is how many of them converted.</dd></div>
            </dl>
        </details>
    @endif
</x-layouts.app>
