@use('App\Models\MniDepartment')
@use('Carbon\CarbonImmutable')
@use('App\Models\MniInput')
@php
    $link = fn (CarbonImmutable $m, string $p) => route('mni.index', ['month' => $m->format('Y-m'), 'plan' => $p]);
    $cell = 'h-8 w-28 rounded-lg border border-[#e9cf6f] bg-[#fffbea] px-2 text-right text-sm font-semibold tabular-nums placeholder:font-normal placeholder:text-muted/60 focus:border-brand-400 focus:bg-white focus:ring-2 focus:ring-brand-200 focus:outline-none';
    $number = fn ($value) => $value === null ? '' : rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    $department = $data['department_inputs'];
@endphp

<x-layouts.app title="Monthly MNI">
    <div class="space-y-4" data-mni data-url="{{ route('mni.update') }}" data-ledger-url="{{ $link($month, $plan) }}" data-month="{{ $month->format('Y-m') }}" data-plan="{{ $plan }}">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-xl font-semibold">Monthly MNI <span class="text-sm font-normal text-muted">· Targets per CRA</span></h1>

            <div class="flex flex-wrap items-center gap-2">
                <nav class="flex h-9 overflow-hidden rounded-lg border border-line bg-white text-sm font-semibold" aria-label="Plan">
                    @foreach (MniDepartment::PLANS as $key => $name)
                        <a href="{{ $link($month, $key) }}" @if ($key === $plan) aria-current="page" @endif
                           @class(['grid place-items-center px-4', 'bg-brand-600 text-white' => $key === $plan, 'text-ink hover:text-brand-600' => $key !== $plan])>{{ $name }}</a>
                    @endforeach
                </nav>
                <div class="flex h-9 items-center rounded-lg border border-line bg-white text-sm">
                    <a href="{{ $link($month->subMonth(), $plan) }}" class="grid h-full w-8 place-items-center rounded-l-lg text-muted hover:text-brand-600" aria-label="Previous month">‹</a>
                    <span class="px-2 font-semibold">{{ $month->format('F Y') }}</span>
                    <a href="{{ $link($month->addMonth(), $plan) }}" class="grid h-full w-8 place-items-center rounded-r-lg text-muted hover:text-brand-600" aria-label="Next month">›</a>
                </div>
            </div>
        </header>

        <div role="note" class="rounded-lg border border-[#e0b400]/60 bg-[#fff6d6] px-4 py-2 text-sm text-[#473821]">
            <span class="font-semibold">How it works:</span>
            @if ($canEdit)
                type each CRA's targets in the yellow boxes; they save when you leave a box and the statement below updates.
            @else
                the targets are set by the CRA Supervisor or Team Lead.
            @endif
            Tax Allocation and Advertising Cost are the department's, split evenly across the CRAs. Operating Costs and NET INCOME show — until their formulas are in.
        </div>

        <x-panel title="Inputs" icon="target" :tinted="false" body-class="">
            <x-slot:badges>
                <span class="rounded-full bg-white px-2.5 py-0.5 text-xs font-semibold text-brand-700 shadow-sm">{{ $month->format('F Y') }} · {{ MniDepartment::PLANS[$plan] }}</span>
            </x-slot:badges>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[960px] text-sm tabular-nums">
                    <thead class="bg-canvas/60 text-xs tracking-wide text-muted uppercase">
                        <tr>
                            <th class="px-4 py-2.5 text-left font-semibold">CRA</th>
                            @foreach (MniInput::FIELDS as $field => $name)
                                <th class="px-3 py-2.5 text-right font-semibold">{{ $name }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($cras as $cra)
                            @php($input = $data['inputs']->get($cra->id))
                            <tr>
                                <td class="px-4 py-2 font-semibold whitespace-nowrap">{{ $cra->displayName() }}</td>
                                @foreach (MniInput::FIELDS as $field => $name)
                                    <td class="px-3 py-1.5 text-right">
                                        @if ($canEdit)
                                            <input type="text" inputmode="decimal" data-mni-input data-cra="{{ $cra->id }}" data-field="{{ $field }}" value="{{ $number($input?->{$field}) }}" placeholder="—"
                                                   aria-label="{{ $name }} for {{ $cra->displayName() }}" class="{{ $cell }}">
                                        @else
                                            {{ $input?->{$field} === null ? '—' : $number($input->{$field}) }}
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-8 text-center text-muted">No active CRAs yet.</td></tr>
                        @endforelse
                        <tr class="bg-brand-50/60">
                            <td class="px-4 py-2 font-bold whitespace-nowrap text-brand-700">Department</td>
                            <td colspan="6" class="px-3 py-2">
                                <div class="flex flex-wrap items-center justify-end gap-x-6 gap-y-2">
                                    @foreach (MniDepartment::FIELDS as $field => $name)
                                        <label class="flex items-center gap-2 font-semibold">
                                            {{ $name }} <span class="text-xs font-normal text-muted">(whole department)</span>
                                            @if ($canEdit)
                                                <input type="text" inputmode="decimal" data-mni-input data-field="{{ $field }}" value="{{ $number($department->{$field}) }}" placeholder="—" class="{{ $cell }} w-32">
                                            @else
                                                <span>{{ $department->{$field} === null ? '—' : number_format($department->{$field}, 2) }}</span>
                                            @endif
                                        </label>
                                    @endforeach
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </x-panel>

        <x-panel title="Statement" icon="table" :tinted="false" body-class="">
            <x-slot:actions>
                <span class="text-muted">Worked out from the inputs</span>
            </x-slot:actions>
            <div class="x-scrollbar overflow-x-auto" data-mni-ledger-host>
                @include('mni._ledger')
            </div>
        </x-panel>
    </div>
</x-layouts.app>
