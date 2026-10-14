@php
    $control = 'h-9 rounded-lg border border-line bg-white px-2.5 text-sm text-ink focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none';
    $state = array_filter(['product' => $group['slug'], 'date' => $day->toDateString(), 'cra' => $craId]);
    $dayLink = fn (\Carbon\CarbonImmutable $d) => route('pc-report.index', [...$state, 'date' => $d->toDateString()]);
    $dash = '<span class="text-muted">—</span>';
    $columns = ['Total Inquiries', 'Total Engagement', '# of Orders', 'Conversion Rate', 'Amount'];
@endphp

<x-layouts.app title="PC Report">
    <div class="space-y-4">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-xl font-semibold">PC Report <span class="text-sm font-normal text-muted">· Each CRA's pages per product</span></h1>

            <form method="GET" action="{{ route('pc-report.index') }}" class="flex flex-wrap items-center gap-2">
                <label>
                    <span class="sr-only">Product</span>
                    <select name="product" onchange="this.form.submit()" class="{{ $control }} font-semibold">
                        @foreach ($groups as $g)
                            <option value="{{ $g['slug'] }}" @selected($g['slug'] === $group['slug'])>{{ $g['name'] }} · {{ count($g['pages']) }} pages</option>
                        @endforeach
                    </select>
                </label>

                <div class="flex h-9 items-center rounded-lg border border-line bg-white text-sm">
                    <a href="{{ $dayLink($day->subDay()) }}" class="grid h-full w-8 place-items-center rounded-l-lg text-muted hover:text-brand-600" aria-label="Previous day">‹</a>
                    <label>
                        <span class="sr-only">Day</span>
                        <input type="date" name="date" value="{{ $day->toDateString() }}" max="{{ $today->toDateString() }}" onchange="this.form.submit()"
                               class="h-8 border-0 bg-transparent px-1 text-sm font-semibold focus:ring-0 focus:outline-none">
                    </label>
                    @if ($day->lessThan($today))
                        <a href="{{ $dayLink($day->addDay()) }}" class="grid h-full w-8 place-items-center rounded-r-lg text-muted hover:text-brand-600" aria-label="Next day">›</a>
                    @else
                        <span class="grid h-full w-8 place-items-center text-muted/40" aria-hidden="true">›</span>
                    @endif
                </div>

                @if ($allCras)
                    <label>
                        <span class="sr-only">CRA</span>
                        <select name="cra" onchange="this.form.submit()" class="{{ $control }}">
                            <option value="">All CRAs</option>
                            @foreach ($allCras as $cra)
                                <option value="{{ $cra->id }}" @selected((int) $craId === $cra->id)>{{ $cra->displayName() }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
            </form>
        </header>

        @if ($errors->any())
            <div role="alert" class="rounded-lg border border-coral/60 bg-coral/10 px-4 py-3 text-sm text-coral-700">{{ $errors->first() }}</div>
        @endif

        <div role="note" class="rounded-lg border border-[#e0b400]/60 bg-[#fff6d6] px-4 py-2 text-sm text-[#473821]">
            <span class="font-semibold">Preview.</span> The layout is ready; inquiries, engagement, orders and amounts are not fetched yet, and the products are sample groups until they're saved in Settings → Pancake Pages.
        </div>

        <p class="flex items-center gap-1.5 text-xs text-muted">
            @if ($canEdit)
                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 20h4L19 9l-4-4L4 16z"/></svg>
                Click a Creation/Update Time to set the month range each CRA's page pulls inquiries and engagement from.
            @else
                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
                The Creation/Update Time is set by your CRA Supervisor.
            @endif
        </p>

        @forelse ($cras as $cra)
            <x-panel :title="$group['name'].' ('.$cra->displayName().')'" icon="table" :tinted="false" body-class="">
                <x-slot:badges>
                    <span class="rounded-full bg-white px-2.5 py-0.5 text-xs font-semibold text-brand-700 shadow-sm">{{ $day->format('D, M j') }}</span>
                </x-slot:badges>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[960px] text-sm tabular-nums">
                        <thead class="bg-canvas/60 text-xs tracking-wide text-muted uppercase">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold">Page</th>
                                @foreach ($columns as $column)
                                    <th class="px-4 py-3 text-right font-semibold">{{ $column }}</th>
                                @endforeach
                                <th class="px-4 py-3 text-left font-semibold">Tagging</th>
                                <th class="px-4 py-3 text-left font-semibold">Creation/Update Time</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($group['pages'] as $page)
                                <tr data-pc-row data-page="{{ $page }}">
                                    <td class="px-4 py-2.5 font-medium whitespace-nowrap">
                                        <span class="inline-flex items-center gap-2">
                                            @if ($canEdit)
                                                <button type="button" data-pc-split aria-label="Split {{ $page }} into Dormant and Active" title="Split into Dormant and Active"
                                                        class="grid size-5 shrink-0 place-items-center rounded-md border border-line text-sm leading-none text-muted transition hover:border-brand-400 hover:text-brand-600 focus-visible:ring-2 focus-visible:ring-brand-200 focus-visible:outline-none">+</button>
                                            @endif
                                            <span>PC - {{ $page }}<span data-pc-category></span></span>
                                        </span>
                                    </td>
                                    @foreach ($columns as $column)
                                        <td class="px-4 py-2.5 text-right">{!! $dash !!}</td>
                                    @endforeach
                                    <td class="px-4 py-2"><input type="text" data-pc-tagging maxlength="50" placeholder="e.g. SW1, SJJ" autocomplete="off" spellcheck="false" aria-label="Tagging for {{ $cra->displayName() }}, {{ $page }}" class="h-8 w-32 rounded-lg border border-line bg-white px-2 text-xs font-semibold uppercase placeholder:font-normal placeholder:normal-case focus:border-brand-400 focus:ring-2 focus:ring-brand-200 focus:outline-none"></td>
                                    <td class="px-4 py-2.5">
                                        @if ($canEdit)
                                            <button type="button" data-pc-window data-cra="{{ $cra->displayName() }}" data-page="{{ $page }}" data-from="" data-to=""
                                                    aria-label="Creation/Update Time for {{ $cra->displayName() }}, {{ $page }}"
                                                    class="inline-flex items-center gap-1.5 rounded-full bg-[#fff6d6] px-2.5 py-1 text-xs font-semibold whitespace-nowrap text-[#7a5c00] transition hover:ring-2 hover:ring-brand-200 focus-visible:ring-2 focus-visible:ring-brand-200 focus-visible:outline-none data-[set]:bg-brand-50 data-[set]:text-brand-700">
                                                <span data-pc-window-label>Not set</span>
                                                <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 20h4L19 9l-4-4L4 16z"/></svg>
                                            </button>
                                        @else
                                            <span class="inline-flex items-center gap-1.5 rounded-full bg-canvas px-2.5 py-1 text-xs font-semibold whitespace-nowrap text-muted" title="Set by your CRA Supervisor">
                                                Not set
                                                <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            <tr class="border-t-2 border-line bg-brand-50/50 font-bold">
                                <td class="px-4 py-2.5">TOTAL</td>
                                @foreach ($columns as $column)
                                    <td class="px-4 py-2.5 text-right">{!! $dash !!}</td>
                                @endforeach
                                <td class="px-4 py-2.5"></td>
                                <td class="px-4 py-2.5"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </x-panel>
        @empty
            <p class="rounded-xl bg-white px-4 py-10 text-center text-sm text-muted shadow-sm">No active CRAs to show.</p>
        @endforelse
    </div>

    @if ($canEdit)
        {{-- Creation/Update Time: the month range a CRA's page pulls inquiries and engagement from (preview: kept on this screen only) --}}
        <dialog id="pc-window-dialog" data-year="{{ $today->year }}" data-month="{{ $today->format('Y-m') }}" aria-labelledby="pc-window-title" class="m-auto w-[min(26rem,calc(100%-2rem))] rounded-xl p-0 shadow-2xl backdrop:bg-ink/40">
            <div class="space-y-4 p-5">
                <div>
                    <h2 id="pc-window-title" class="text-base font-bold">Creation/Update Time</h2>
                    <p class="text-sm text-muted" data-pc-window-for></p>
                </div>
                <p class="text-xs text-muted">Inquiries and engagement count the conversations created or updated from the first month to the last. Click the first month, then the last; the range can cross years.</p>

                <div class="flex items-center justify-between">
                    <button type="button" data-pc-year="-1" class="grid size-8 place-items-center rounded-lg border border-line hover:border-brand-400 hover:text-brand-600" aria-label="Previous year">‹</button>
                    <span class="font-bold" data-pc-year-label></span>
                    <button type="button" data-pc-year="1" class="grid size-8 place-items-center rounded-lg border border-line hover:border-brand-400 hover:text-brand-600" aria-label="Next year">›</button>
                </div>

                <div class="grid grid-cols-4 gap-1.5">
                    @foreach (['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'] as $i => $month)
                        <button type="button" data-pc-month="{{ $i + 1 }}" aria-pressed="false"
                                class="h-9 rounded-lg border border-line text-sm font-medium transition hover:border-brand-400 data-[range]:border-brand-200 data-[range]:bg-brand-50 data-[range]:text-brand-700 aria-pressed:border-brand-600 aria-pressed:bg-brand-600 aria-pressed:text-white">{{ $month }}</button>
                    @endforeach
                </div>

                <p class="text-sm"><span class="font-semibold">Range:</span> <span data-pc-window-summary>None</span></p>
                <p class="rounded-lg bg-[#fff6d6] px-3 py-2 text-xs text-[#473821]">Preview: the range stays on this screen only until saving is built.</p>

                <div class="flex items-center justify-between gap-2">
                    <button type="button" data-pc-clear class="h-10 rounded-lg border border-line px-3 font-medium hover:border-brand-400 hover:text-brand-600">Clear</button>
                    <div class="flex gap-2">
                        <button type="button" data-pc-cancel class="h-10 rounded-lg border border-line px-3 font-medium hover:border-brand-400 hover:text-brand-600">Cancel</button>
                        <button type="button" data-pc-save class="h-10 rounded-lg bg-brand-600 px-4 font-semibold text-white shadow-sm hover:bg-brand-700">Save</button>
                    </div>
                </div>
            </div>
        </dialog>
    @endif
</x-layouts.app>
