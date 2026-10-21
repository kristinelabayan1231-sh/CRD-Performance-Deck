@php
    $money = fn (float $value) => '₱'.number_format($value, 2);
    $plain = fn (float $value) => number_format($value, 2);
    $payTypes = config('monthly_cost.pay_types');
    $days = $cost->workingDays() + 0;
    // Daily rates are listed for reference only; each row's monthly salary is typed in.
    $rates = collect($payTypes)->except(\App\Models\MonthlyCostRow::FIXED)->map(fn ($label, $type) => $cost->dailyRate($type) + 0);
    $moneyField = 'h-9 w-full rounded-lg border border-line bg-white px-2.5 text-right text-sm tabular-nums focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none read-only:bg-canvas read-only:text-muted';
    // Which row's form was sent back with errors ('new' for Add person): it opens again with what was typed.
    $editing = old('editing');
    $total = max(0.01, $totals['total']);
    $parts = [
        ['Customer Retention', $totals['customer_retention'], 'bg-brand-600'],
        ['13th Month', $totals['thirteenth_month'], 'bg-[#1f8fb8]'],
        ['SIL', $totals['sil'], 'bg-[#eb6834]'],
        ['Gov. Benefits', $totals['gov_benefits'], 'bg-teal-700'],
    ];
@endphp

<x-layouts.app title="CRD - MC">
    <div class="space-y-5" data-monthly-cost>
        <header class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">CRD - MC</h1>
                <p class="text-xs text-muted">
                    What the Customer Retention Dept. costs each month.
                    @if ($lastChange?->editor)
                        Last edited by {{ $lastChange->editor->displayName() }} · {{ $lastChange->updated_at->timezone(config('segmentation.timezone'))->format('M j, g:i A') }}
                    @endif
                </p>
            </div>
            <button type="button" data-cost-add-open class="h-10 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">+ Add person</button>
        </header>

        @if ($errors->any())
            <div role="alert" class="rounded-lg border border-coral/60 bg-coral/10 px-4 py-3 text-sm text-coral-700">{{ $errors->first() }}</div>
        @endif

        {{-- The total and its four parts --}}
        <section aria-label="Monthly cost" class="grid grid-cols-2 gap-3 lg:grid-cols-6">
            <div class="relative col-span-2 overflow-hidden rounded-xl bg-gradient-to-br from-brand-600 to-brand-700 p-4 text-white shadow-sm">
                <span aria-hidden="true" class="absolute -top-7 -right-7 size-24 rounded-full bg-white/15"></span>
                <p class="relative text-[11px] font-semibold tracking-wide uppercase">Total monthly cost</p>
                <p class="relative mt-1 text-3xl font-bold tabular-nums">{{ $money($totals['total']) }}</p>
                <p class="relative text-xs text-white/90">{{ $totals['people'] }} {{ Str::plural('person', $totals['people']) }}{{ $totals['people'] ? ' · '.$money($totals['total'] / $totals['people']).' per person' : '' }}</p>
            </div>
            @foreach ([
                ['Customer Retention', $totals['customer_retention'], 'Total salary of CRD', 'bg-brand-600'],
                ['13th Month', $totals['thirteenth_month'], 'Customer Retention ÷ 12', 'bg-[#1f8fb8]'],
                ['SIL', $totals['sil'], 'Customer Retention × 12 ÷ '.config('monthly_cost.sil_days'), 'bg-[#eb6834]'],
                ['Gov. Benefits', $totals['gov_benefits'], $plain($cost->govBenefitsPerPerson()).' × '.$totals['people'].' '.Str::plural('person', $totals['people']), 'bg-teal-700'],
            ] as [$label, $amount, $formula, $dot])
                <div class="rounded-xl border border-line bg-white p-4 shadow-sm">
                    <p class="flex items-center gap-2 text-[11px] font-semibold tracking-wide text-muted uppercase">
                        <span aria-hidden="true" class="size-2.5 rounded-sm {{ $dot }}"></span>{{ $label }}
                    </p>
                    <p class="mt-1 text-xl font-bold tabular-nums">{{ $money($amount) }}</p>
                    <p class="text-xs text-muted">{{ $formula }}</p>
                </div>
            @endforeach
        </section>

        {{-- Where the total goes --}}
        <section class="rounded-xl bg-white p-4 shadow-sm">
            <div class="mb-2 flex justify-between text-xs text-muted"><span class="font-semibold text-ink">Where it goes</span><span class="tabular-nums">{{ $money($totals['total']) }}</span></div>
            <div role="img" aria-label="{{ collect($parts)->map(fn ($p) => $p[0].' '.round($p[1] / $total * 100, 1).'%')->join(', ') }}" class="flex h-3.5 gap-0.5 overflow-hidden rounded-full bg-canvas">
                @foreach ($parts as [$label, $amount, $color])
                    @if ($amount > 0)
                        <span class="{{ $color }}" style="width: {{ round($amount / $total * 100, 2) }}%" title="{{ $label }}: {{ $money($amount) }}"></span>
                    @endif
                @endforeach
            </div>
            <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">
                @foreach ($parts as [$label, $amount])
                    <span>{{ $label }} {{ round($amount / $total * 100, 1) }}%</span>
                @endforeach
            </div>
        </section>

        <div class="flex flex-wrap items-start gap-5">
            {{-- Team salaries --}}
            <x-panel title="Team salaries" icon="users" class="min-w-0 flex-[999_1_620px]" :tinted="false" body-class="">
                <x-slot:badges>
                    <span class="rounded-full bg-white px-2 py-0.5 text-xs font-bold text-brand-700 tabular-nums shadow-sm">{{ $rows->count() }}</span>
                </x-slot:badges>
                <x-slot:actions>
                    <span class="text-sm font-bold text-ink tabular-nums">{{ $money($totals['customer_retention']) }}</span>
                </x-slot:actions>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[480px] text-left text-sm">
                        <thead class="bg-[#f7f4f8] text-xs tracking-wide text-muted uppercase">
                            <tr>
                                <th class="px-4 py-3 font-semibold">Name · Position</th>
                                <th class="px-3 py-3 text-right font-semibold">Monthly salary</th>
                                <th class="px-3 py-3 text-right font-semibold"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @include('monthly-cost._edit-row', ['row' => null, 'formId' => 'cost-row-new', 'open' => $editing === 'new'])

                            @forelse ($rows as $row)
                                @php($isEditing = $editing === (string) $row->id)
                                <tr data-cost-view="{{ $row->id }}" @if ($isEditing) hidden @endif class="align-top">
                                    <td class="px-4 py-3">
                                        @if ($row->isFilled())
                                            <span class="font-semibold">{{ $row->name }}</span>
                                        @else
                                            <span class="font-semibold text-muted italic">Vacant</span>
                                        @endif
                                        <span class="block text-xs text-muted">{{ $row->position }}@if ($row->cra) · <span class="font-semibold text-teal-700">CRA: {{ $row->cra->displayName() }}</span>@endif</span>
                                    </td>
                                    <td class="px-3 py-3 text-right tabular-nums">
                                        <span class="font-semibold">{{ $plain($row->monthlySalary()) }}</span>
                                        @if (! $row->isFilled())
                                            <span class="block text-xs text-muted">Vacant · not counted</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-right whitespace-nowrap">
                                        <button type="button" data-cost-edit="{{ $row->id }}" aria-label="Edit {{ $row->name ?: $row->position }}" class="rounded-lg p-2 text-muted hover:bg-brand-50 hover:text-brand-600">
                                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 20h4L19 9l-4-4L4 16v4Zm9-13 4 4"/></svg>
                                        </button>
                                        <form method="POST" action="{{ route('monthly-cost.destroy', $row) }}" class="inline" onsubmit="return confirm('Delete {{ e(addslashes($row->name ?: $row->position)) }}\'s row?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" aria-label="Delete {{ $row->name ?: $row->position }}" class="rounded-lg p-2 text-coral-700 hover:bg-coral/10">
                                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                @include('monthly-cost._edit-row', ['row' => $row, 'formId' => 'cost-row-'.$row->id, 'open' => $isEditing])
                            @empty
                                <tr data-cost-empty>
                                    <td colspan="3" class="px-4 py-10 text-center text-sm text-muted">No positions yet. Use + Add person to add the first one.</td>
                                </tr>
                            @endforelse

                            {{-- The shared salaries count toward TOTAL SALARY OF CRD --}}
                            <tr class="bg-brand-50/60">
                                <td colspan="3" class="px-4 py-2 text-[11px] font-semibold tracking-wide text-brand-700 uppercase">Shared salaries</td>
                            </tr>
                            @foreach ($shared as $part)
                                @php($sharedKey = 'shared-'.$part['key'])
                                @php($isEditing = $editing === $sharedKey)
                                <tr data-cost-view="{{ $sharedKey }}" @if ($isEditing) hidden @endif>
                                    <td class="px-4 py-2.5 text-muted">
                                        {{ $part['share'] + 0 }}% of {{ $part['label'] }}
                                        <span class="block text-xs tabular-nums">₱{{ number_format($part['salary'], 2) }} × {{ $part['share'] + 0 }}%</span>
                                    </td>
                                    <td class="px-3 py-2.5 text-right font-semibold tabular-nums">{{ $plain($part['amount']) }}</td>
                                    <td class="px-3 py-2 text-right whitespace-nowrap">
                                        <button type="button" data-cost-edit="{{ $sharedKey }}" aria-label="Edit {{ $part['label'] }}'s shared salary" class="rounded-lg p-2 text-muted hover:bg-brand-50 hover:text-brand-600">
                                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 20h4L19 9l-4-4L4 16v4Zm9-13 4 4"/></svg>
                                        </button>
                                    </td>
                                </tr>
                                {{-- Edit in place: saves only this salary and CRD's share --}}
                                @php($formId = 'cost-'.$sharedKey)
                                @php($sharedValue = fn (string $field) => $isEditing ? old($field) : $cost->setting($field) + 0)
                                <tr data-cost-form="{{ $sharedKey }}" @unless ($isEditing) hidden @endunless class="bg-brand-50/70 align-top">
                                    <td class="px-4 py-3">
                                        <span class="font-semibold">{{ $part['label'] }}</span>
                                        <label class="mt-1.5 flex items-center gap-2 text-xs text-muted">
                                            CRD's share (%)
                                            <input form="{{ $formId }}" name="{{ $part['key'] }}_share" type="number" min="0" max="100" step="0.01" required value="{{ $sharedValue($part['key'].'_share') }}"
                                                   class="h-8 w-24 rounded-lg border border-line bg-white px-2.5 text-right text-sm tabular-nums focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
                                        </label>
                                    </td>
                                    <td class="px-3 py-3 text-right">
                                        <label class="sr-only" for="{{ $formId }}-salary">{{ $part['label'] }} salary</label>
                                        <input id="{{ $formId }}-salary" form="{{ $formId }}" name="{{ $part['key'] }}_salary" type="number" min="0" step="0.01" required value="{{ $sharedValue($part['key'].'_salary') }}"
                                               class="h-9 w-32 rounded-lg border border-line bg-white px-2.5 text-right text-sm tabular-nums focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
                                        <span class="mt-1 block text-xs text-muted">Full salary</span>
                                    </td>
                                    <td class="px-3 py-3 text-right whitespace-nowrap">
                                        <button type="submit" form="{{ $formId }}" class="h-9 rounded-lg bg-brand-600 px-3 text-xs font-semibold text-white hover:bg-brand-700">Save</button>
                                        <button type="button" data-cost-cancel="{{ $sharedKey }}" class="h-9 rounded-lg border border-line bg-white px-3 text-xs font-semibold">Cancel</button>
                                    </td>
                                </tr>
                            @endforeach
                            <tr class="border-t-2 border-ink">
                                <td class="px-4 py-3 font-bold">TOTAL SALARY OF CRD</td>
                                <td class="px-3 py-3 text-right text-base font-bold tabular-nums">{{ $plain($totals['customer_retention']) }}</td>
                                <td></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                {{-- One form per row; the inputs in the table point to it --}}
                <form id="cost-row-new" method="POST" action="{{ route('monthly-cost.store') }}">@csrf<input type="hidden" name="editing" value="new"></form>
                @foreach ($rows as $row)
                    <form id="cost-row-{{ $row->id }}" method="POST" action="{{ route('monthly-cost.update', $row) }}">@csrf @method('PATCH')<input type="hidden" name="editing" value="{{ $row->id }}"></form>
                @endforeach
                @foreach ($shared as $part)
                    <form id="cost-shared-{{ $part['key'] }}" method="POST" action="{{ route('monthly-cost.settings') }}">@csrf @method('PUT')<input type="hidden" name="editing" value="shared-{{ $part['key'] }}"></form>
                @endforeach
            </x-panel>

            {{-- Settings --}}
            <aside class="flex flex-[1_1_300px] flex-col gap-4 text-sm">
                <section class="rounded-xl bg-white p-4 shadow-sm">
                    <h2 class="mb-2 font-bold">Shared salaries</h2>
                    @foreach ($shared as $part)
                        <div class="flex justify-between gap-2 border-t border-line py-2">
                            <span>{{ $part['label'] }}<span class="block text-xs text-muted tabular-nums">₱{{ number_format($part['salary'], 2) }} × {{ $part['share'] + 0 }}%</span></span>
                            <span class="font-semibold tabular-nums">{{ $plain($part['amount']) }}</span>
                        </div>
                    @endforeach
                </section>
                {{-- Editable in place: saves only PHIC, HDMF and SSS --}}
                <form method="POST" action="{{ route('monthly-cost.settings') }}" class="rounded-xl bg-white p-4 shadow-sm">
                    @csrf
                    @method('PUT')
                    <h2 class="mb-2 font-bold">Gov. benefits per person</h2>
                    @foreach (['phic' => 'PHIC', 'hdmf' => 'HDMF', 'sss' => 'SSS'] as $key => $label)
                        <label class="flex items-center justify-between gap-3 py-1">
                            <span>{{ $label }}</span>
                            <input type="number" name="{{ $key }}" required min="0" step="0.01" value="{{ old('settings') ? number_format($cost->setting($key), 2, '.', '') : old($key, number_format($cost->setting($key), 2, '.', '')) }}"
                                   class="h-8 w-32 rounded-lg border border-line bg-white px-2.5 text-right text-sm tabular-nums focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
                        </label>
                    @endforeach
                    <div class="mt-1 flex justify-between border-t border-line pt-2 font-bold"><span>Total GB</span><span class="tabular-nums">{{ $plain($cost->govBenefitsPerPerson()) }}</span></div>
                    <button type="submit" class="mt-3 h-9 w-full rounded-lg bg-brand-600 font-semibold text-white hover:bg-brand-700">Save gov. benefits</button>
                </form>
                <section class="rounded-xl bg-white p-4 shadow-sm">
                    <h2 class="mb-2 font-bold">Daily rates &amp; incentives</h2>
                    @foreach ($rates as $type => $rate)
                        <div class="flex justify-between py-1"><span>{{ $payTypes[$type] }}</span><span class="tabular-nums">₱{{ number_format($rate, 2) }}/day</span></div>
                    @endforeach
                    <div class="flex justify-between py-1 text-muted"><span>Working days a month</span><span class="tabular-nums">{{ $days }}</span></div>
                    @foreach (['reliability_reward' => 'Reliability Reward', 'rice_incentives' => 'Rice Incentives', 'free_meal' => '1-month Free Meal'] as $key => $label)
                        <div @class(['flex justify-between py-1', 'mt-1 border-t border-line pt-2' => $loop->first])><span>{{ $label }}</span><span class="tabular-nums">₱{{ number_format($cost->setting($key), 2) }}</span></div>
                    @endforeach
                    <p class="mt-1 text-xs text-muted">Daily rates are for reference: each person's monthly salary is typed in their row. Incentives are per person and aren't added to the total.</p>
                    <button type="button" data-cost-settings-open class="mt-3 h-9 w-full rounded-lg border border-line font-semibold hover:border-brand-400 hover:text-brand-600">Edit settings</button>
                </section>
            </aside>
        </div>

        <details class="rounded-xl bg-white p-4 text-sm shadow-sm">
            <summary class="cursor-pointer font-semibold">How the numbers are worked out</summary>
            <dl class="mt-3 grid gap-x-6 gap-y-2 text-muted sm:grid-cols-2">
                <div><dt class="font-semibold text-ink">Monthly salary</dt><dd>The amount typed in each row. A row with no name is a vacant position and counts ₱0. A row marked with a CRA is that CRA's own salary in the Monthly MNI; every other row and the shared salaries are split evenly across the CRAs there.</dd></div>
                <div><dt class="font-semibold text-ink">Customer Retention (TOTAL SALARY OF CRD)</dt><dd>Every row's monthly salary plus the shared salaries: Sales Manager, VP of Internal Affairs and CEO, each salary × CRD's share.</dd></div>
                <div><dt class="font-semibold text-ink">13th Month · SIL</dt><dd>13th Month = Customer Retention ÷ 12. SIL = Customer Retention × 12 ÷ {{ config('monthly_cost.sil_days') }}.</dd></div>
                <div><dt class="font-semibold text-ink">Gov. Benefits</dt><dd>(PHIC + HDMF + SSS) × the people in CRD: every row with a name ({{ $totals['people'] }} now).</dd></div>
                <div><dt class="font-semibold text-ink">Total monthly cost</dt><dd>Customer Retention + 13th Month + SIL + Gov. Benefits, added before rounding (as in the sheet), so the parts shown can be a centavo off the total.</dd></div>
            </dl>
        </details>
    </div>

    {{-- Settings pop-up --}}
    <dialog id="cost-settings" aria-labelledby="cost-settings-title" @if (old('settings')) data-open @endif
            class="m-auto max-h-[90dvh] w-[min(44rem,calc(100%-2rem))] overflow-hidden rounded-xl p-0 shadow-2xl backdrop:bg-ink/40">
        <form method="POST" action="{{ route('monthly-cost.settings') }}" class="flex max-h-[90dvh] flex-col">
            @csrf
            @method('PUT')
            <input type="hidden" name="settings" value="1">
            <div class="flex shrink-0 items-center justify-between gap-4 bg-gradient-to-r from-brand-600 to-brand-700 px-5 py-3 text-white">
                <h2 id="cost-settings-title" class="text-base font-bold">CRD - MC settings</h2>
                <button type="button" data-cost-settings-close aria-label="Close" class="rounded-lg p-1.5 text-white/80 hover:bg-white/15 hover:text-white">
                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>
            <div class="min-h-0 space-y-5 overflow-y-auto p-5 text-sm">
                @foreach ([
                    'Shared salaries' => [['sales_manager_salary', 'Sales Manager salary', '₱'], ['sales_manager_share', 'CRD\'s share', '%'], ['vp_salary', 'VP of Internal Affairs salary', '₱'], ['vp_share', 'CRD\'s share', '%'], ['ceo_salary', 'CEO salary', '₱'], ['ceo_share', 'CRD\'s share', '%']],
                    'Gov. benefits per person' => [['phic', 'PHIC', '₱'], ['hdmf', 'HDMF', '₱'], ['sss', 'SSS', '₱']],
                    'Daily rates' => [['rate_trainee', 'Trainee', '₱'], ['rate_probationary', 'Probationary', '₱'], ['rate_regular', 'Regular', '₱'], ['working_days', 'Working days a month', '']],
                    'Incentives (per person, not in the total)' => [['reliability_reward', 'Reliability Reward', '₱'], ['rice_incentives', 'Rice Incentives', '₱'], ['free_meal', '1-month Free Meal', '₱']],
                ] as $group => $inputs)
                    <fieldset>
                        <legend class="mb-2 font-bold">{{ $group }}</legend>
                        <div @class(['grid gap-3', 'sm:grid-cols-2' => $loop->first, 'sm:grid-cols-3' => ! $loop->first && count($inputs) === 3, 'sm:grid-cols-4' => count($inputs) === 4])>
                            @foreach ($inputs as [$key, $label, $unit])
                                <label class="block">
                                    <span class="mb-1 block text-xs font-semibold text-muted">{{ $label }}{{ $unit === '%' ? ' (%)' : '' }}</span>
                                    <input type="number" name="{{ $key }}" required min="0" step="0.01" @if ($unit === '%') max="100" @endif
                                           value="{{ old($key, $cost->setting($key) + 0) }}" class="{{ $moneyField }}">
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endforeach
            </div>
            <div class="flex shrink-0 justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" data-cost-settings-close class="h-9 rounded-lg border border-line px-4 text-sm font-semibold">Cancel</button>
                <button type="submit" class="h-9 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">Save settings</button>
            </div>
        </form>
    </dialog>
</x-layouts.app>
