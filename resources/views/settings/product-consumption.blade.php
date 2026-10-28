<x-layouts.settings title="Product Consumption">
    @php($canManage = auth()->user()->can('product_consumption.manage'))
    {{-- TSD upsell products: everyone who may manage products, and roles given only these (CRA Supervisors). --}}
    @php($canTsd = $canManage || auth()->user()->can('product_consumption.tsd'))
    {{-- Roles that may only price products get an SRP field in the panel. --}}
    @php($canSetSrp = auth()->user()->can('product_consumption.srp'))
    @php($input = 'h-10 w-full rounded-lg border border-line bg-white px-3 focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none')
    @php($label = 'mb-1 block text-xs font-semibold')
    @php($here = fn (array $query = []) => route('settings.product-consumption.index', array_filter(['source' => $source, ...$query], fn ($value) => $value !== null)))
    @php($panelOpen = $add !== null || $selected !== null)
    @php($money = fn ($value) => $value !== null ? '₱'.number_format((float) $value, 2) : '—')

    {{-- Source filter and add button --}}
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <nav aria-label="Product source" class="inline-flex gap-0.5 rounded-xl bg-white p-1 shadow-sm">
            @foreach ([null => ['All', $counts['all']], 'crd' => ['CRD', $counts['crd']], 'tsd' => ['TSD upsell', $counts['tsd']]] as $key => [$name, $count])
                @php($active = $source === ($key ?: null))
                <a href="{{ route('settings.product-consumption.index', array_filter(['source' => $key ?: null])) }}" @if ($active) aria-current="page" @endif
                   @class([
                       'rounded-lg px-3 py-1.5 text-sm font-semibold transition',
                       'bg-brand-50 text-brand-600' => $active,
                       'text-muted hover:text-brand-600' => ! $active,
                   ])>{{ $name }} <span class="font-normal tabular-nums opacity-70">{{ $count }}</span></a>
            @endforeach
        </nav>
        <div class="flex gap-2">
            @if ($canManage)
                <a href="{{ $here(['add' => $source === 'tsd' ? 'tsd' : 'crd']) }}" class="inline-flex h-10 items-center rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-700">+ Add product</a>
            @elseif ($canTsd)
                <a href="{{ $here(['add' => 'tsd']) }}" class="inline-flex h-10 items-center rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-700">+ Add TSD product</a>
            @endif
        </div>
    </div>

    <div @class(['grid items-start gap-4', 'xl:grid-cols-[minmax(0,1fr)_22rem]' => $panelOpen])>
        {{-- Products --}}
        <section class="min-w-0 overflow-hidden rounded-xl bg-white shadow-sm">
            <div class="border-b border-line px-4 py-3">
                <h2 class="text-base font-semibold">{{ ['crd' => 'CRD products', 'tsd' => 'TSD upsell products'][$source] ?? 'Products' }}</h2>
                <p class="text-sm text-muted">
                    @if ($source === 'tsd')
                        TSD products CRAs may upsell to their leads. A CRA's Broadcast or Segmentation order with one counts in that CRA's gross sales (CVR Tracker, Conversion Breakdown, Running Sales, Daily I.S.). It doesn't create leads or change the Customer Database.
                    @else
                        The products the deck counts. Orders with none of these products are left out of the Segmentation Tracker, sales and the Customer Database. TSD upsell products count only in the CRAs' sales.
                    @endif
                    Click a product to see or edit it.
                </p>
            </div>

            @if ($products->isEmpty())
                <p class="px-4 py-10 text-center text-sm text-muted">
                    {{ $source === 'tsd' ? 'No TSD upsell products yet.' : 'No products yet.' }}
                    @if ($source === 'tsd' ? $canTsd : $canManage) Click “+ Add” above to add the first one.@endif
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[760px] text-left text-sm">
                        <thead class="bg-canvas/60 text-xs tracking-wide text-muted uppercase">
                            <tr>
                                <th class="px-4 py-3 font-semibold">Product</th>
                                <th class="px-4 py-3 font-semibold">Source</th>
                                <th class="px-4 py-3 text-right font-semibold">SRP</th>
                                <th class="px-4 py-3 text-right font-semibold">Product cost</th>
                                <th class="px-4 py-3 text-right font-semibold">Days</th>
                                <th class="px-4 py-3 text-right font-semibold">CLTV</th>
                                <th class="px-4 py-3 font-semibold">Starting date</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($products as $product)
                                @php($isSelected = $selected?->is($product))
                                <tr @class(['align-middle transition', 'bg-brand-50/70' => $isSelected, 'hover:bg-canvas/50' => ! $isSelected])>
                                    <td class="px-4 py-2.5">
                                        <a href="{{ $here(['product' => $product->id]) }}" @if ($isSelected) aria-current="true" @endif
                                           class="font-semibold text-ink hover:text-brand-600 focus-visible:text-brand-600">{{ $product->name }}</a>
                                        @if ($product->keywords)
                                            <span class="block text-xs text-muted">{{ $product->keywords }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 whitespace-nowrap">
                                        @if ($product->is_tsd)
                                            <span class="inline-flex items-center gap-1.5 rounded-full bg-teal/15 px-2 py-0.5 text-xs font-semibold text-teal-700"><span class="size-1.5 rounded-full bg-current"></span>TSD upsell</span>
                                        @else
                                            <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-50 px-2 py-0.5 text-xs font-semibold text-brand-600"><span class="size-1.5 rounded-full bg-current"></span>CRD</span>
                                            @if ($product->is_testing)
                                                <span class="ml-1 rounded-full bg-coral/15 px-2 py-0.5 text-xs font-semibold text-coral-700">Testing</span>
                                            @endif
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 text-right tabular-nums">{{ $money($product->srp) }}</td>
                                    <td class="px-4 py-2.5 text-right tabular-nums">{{ $money($product->unit_cost) }}</td>
                                    <td class="px-4 py-2.5 text-right tabular-nums">{{ $product->consumption_days ?? '—' }}</td>
                                    <td class="px-4 py-2.5 text-right whitespace-nowrap tabular-nums text-muted">{{ ! $product->is_tsd && $product->cltv() !== null ? '₱'.number_format($product->cltv()) : '—' }}</td>
                                    <td class="px-4 py-2.5 whitespace-nowrap text-muted">{{ $product->counts_from?->format('M j, Y') ?? 'From the start' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        {{-- Panel: add a product, or see / edit the one picked --}}
        @if ($panelOpen)
            @php($isTsd = $add !== null ? $add === 'tsd' : $selected->is_tsd)
            @php($editable = $add !== null || ($isTsd ? $canTsd : $canManage))
            @php($bag = $add !== null ? $errors->getBag('default') : $errors->getBag("product{$selected->id}"))
            @php($value = fn (string $field, $current = null) => $bag->any() ? old($field, $current) : $current)
            @php($formId = $add !== null ? 'product-add' : "product-{$selected->id}")

            <aside aria-labelledby="product-panel-title" class="rounded-xl bg-white p-4 shadow-lg xl:sticky xl:top-6">
                <div class="mb-3 flex items-start justify-between gap-3">
                    <div>
                        <h2 id="product-panel-title" class="text-base font-semibold">
                            {{ $add !== null ? ($isTsd ? 'Add TSD upsell product' : 'Add product') : $selected->name }}
                        </h2>
                        @if ($selected)
                            <p class="text-xs text-muted">Updated {{ $selected->updated_at?->diffForHumans() }}</p>
                        @endif
                    </div>
                    <a href="{{ $here() }}" aria-label="Close" class="-m-1 rounded-lg p-1 text-muted hover:bg-canvas hover:text-ink">
                        <svg class="size-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/></svg>
                    </a>
                </div>

                @if ($editable)
                    <form id="{{ $formId }}" method="POST" class="grid gap-3"
                          action="{{ $add !== null ? route('settings.product-consumption.store', array_filter(['source' => $source])) : route('settings.product-consumption.update', $selected) }}">
                        @csrf
                        @if ($selected) @method('PATCH') @endif

                        {{-- Source: picked when adding; moved between CRD and TSD only with the full permission. --}}
                        @if ($canManage)
                            <div>
                                <span class="{{ $label }}">Source</span>
                                @if ($add !== null)
                                    <input type="hidden" name="is_tsd" value="{{ $isTsd ? 1 : 0 }}">
                                    <div class="inline-flex gap-0.5 rounded-lg bg-canvas p-0.5">
                                        @foreach (['crd' => 'CRD product', 'tsd' => 'TSD upsell'] as $key => $name)
                                            <a href="{{ $here(['add' => $key]) }}" @class([
                                                'rounded-md px-3 py-1 text-sm font-semibold',
                                                'bg-white text-brand-600 shadow-sm' => $add === $key,
                                                'text-muted hover:text-brand-600' => $add !== $key,
                                            ])>{{ $name }}</a>
                                        @endforeach
                                    </div>
                                @else
                                    <select name="is_tsd" class="{{ $input }}" aria-label="Source of {{ $selected->name }}">
                                        <option value="0" @selected(! $value('is_tsd', $isTsd))>CRD product</option>
                                        <option value="1" @selected($value('is_tsd', $isTsd))>TSD upsell</option>
                                    </select>
                                @endif
                            </div>
                        @elseif ($add !== null)
                            <input type="hidden" name="is_tsd" value="1">
                        @endif

                        <label>
                            <span class="{{ $label }}">Product (keyword)</span>
                            <input type="text" name="name" required maxlength="255" value="{{ $value('name', $selected?->name) }}"
                                   placeholder="{{ $isTsd ? 'e.g. Haplunas' : 'e.g. Pterygium' }}" class="{{ $input }}">
                        </label>
                        <label>
                            <span class="{{ $label }}">Also matches <span class="font-normal text-muted">(comma-separated)</span></span>
                            <input type="text" name="keywords" maxlength="1000" value="{{ $value('keywords', $selected?->keywords) }}"
                                   placeholder="{{ $isTsd ? 'e.g. Haplunas Healing Eye Cream' : 'e.g. Clear Sight, Clearsite' }}" class="{{ $input }}">
                        </label>
                        <label>
                            <span class="{{ $label }}">Not this product if it has</span>
                            <input type="text" name="exclude_keywords" maxlength="1000" value="{{ $value('exclude_keywords', $selected?->exclude_keywords) }}"
                                   placeholder="e.g. Nasal Inhaler" class="{{ $input }}">
                        </label>
                        <div class="grid grid-cols-2 gap-3">
                            <label>
                                <span class="{{ $label }}">SRP <span class="font-normal text-muted">(₱ per unit)</span></span>
                                <input type="number" name="srp" min="0" step="0.01" value="{{ $value('srp', $selected?->srp) }}" placeholder="Not set" class="{{ $input }} tabular-nums">
                            </label>
                            <label>
                                <span class="{{ $label }}">Product cost <span class="font-normal text-muted">(₱)</span></span>
                                <input type="number" name="unit_cost" min="0" step="0.01" value="{{ $value('unit_cost', $selected?->unit_cost) }}" placeholder="Not set" class="{{ $input }} tabular-nums">
                            </label>
                        </div>
                        @unless ($isTsd)
                            <div class="grid grid-cols-2 gap-3">
                                <label>
                                    <span class="{{ $label }}">Consumption days</span>
                                    <input type="number" name="consumption_days" min="1" max="365" value="{{ $value('consumption_days', $selected?->consumption_days) }}" placeholder="e.g. 15" class="{{ $input }} tabular-nums">
                                </label>
                                @if ($add !== null)
                                    <label>
                                        <span class="{{ $label }}">Type</span>
                                        <select name="is_testing" required class="{{ $input }}">
                                            <option value="" disabled @selected(old('is_testing') === null)>Choose</option>
                                            <option value="1" @selected(old('is_testing') === '1')>Testing</option>
                                            <option value="0" @selected(old('is_testing') === '0')>Existing</option>
                                        </select>
                                    </label>
                                @endif
                            </div>
                        @endunless
                        <label>
                            <span class="{{ $label }}">Starting date</span>
                            <input type="date" name="counts_from" @if ($add !== null) required @endif
                                   value="{{ $value('counts_from', $add !== null ? \App\Support\WorkingDate::realToday()->toDateString() : $selected?->counts_from?->toDateString()) }}" class="{{ $input }}">
                        </label>

                        <p class="text-xs text-muted">
                            @if ($isTsd)
                                A CRA's Broadcast or Segmentation order with this product counts in that CRA's gross sales from the starting date. It doesn't create leads or change the Customer Database. Product cost goes into the Daily I.S.
                            @else
                                Orders whose product name contains this name (or an extra keyword) are grouped under it. Consumption days estimate when a customer runs out. SRP sets the CLTV (SRP × {{ config('customers.cltv_units') }}). Orders, deliveries and leads before the starting date leave it out.
                            @endif
                        </p>

                        @if ($bag->any())
                            <ul role="alert" class="space-y-1 text-sm text-coral-700">
                                @foreach ($bag->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </form>

                    <div class="mt-4 flex items-center justify-between gap-2 border-t border-line pt-3">
                        @if ($selected)
                            <form method="POST" action="{{ route('settings.product-consumption.destroy', array_filter(['product' => $selected, 'source' => $source])) }}"
                                  data-confirm="{{ $isTsd ? "Remove {$selected->name}? Its orders stop counting in the CRAs' gross sales." : "Delete {$selected->name}? Only products on this list count: orders with none of them leave leads, sales and the Customer Database, and leads for this product nobody has worked on yet are removed." }}"
                                  onsubmit="return confirm(this.dataset.confirm)">
                                @csrf @method('DELETE')
                                <button type="submit" class="h-10 rounded-lg border border-coral/60 px-3 text-sm font-medium text-coral-700 hover:bg-coral/10">{{ $isTsd ? 'Remove' : 'Delete' }}</button>
                            </form>
                        @else
                            <a href="{{ $here() }}" class="inline-flex h-10 items-center rounded-lg border border-line px-3 text-sm font-medium hover:border-brand-400 hover:text-brand-600">Cancel</a>
                        @endif
                        <button type="submit" form="{{ $formId }}" class="h-10 rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-700">
                            {{ $add !== null ? 'Add product' : 'Save' }}
                        </button>
                    </div>
                @else
                    {{-- Read only, with the SRP for roles that may price products. --}}
                    <dl class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-2 text-sm">
                        <dt class="text-muted">Source</dt><dd class="font-medium">{{ $isTsd ? 'TSD upsell' : 'CRD product' }}{{ ! $isTsd && $selected->is_testing ? ' · Testing' : '' }}</dd>
                        <dt class="text-muted">Also matches</dt><dd>{{ $selected->keywords ?: '—' }}</dd>
                        <dt class="text-muted">Not if it has</dt><dd>{{ $selected->exclude_keywords ?: '—' }}</dd>
                        @unless ($isTsd)
                            <dt class="text-muted">Consumption days</dt><dd class="tabular-nums">{{ $selected->consumption_days ? $selected->consumption_days.' days' : '—' }}</dd>
                        @endunless
                        <dt class="text-muted">Product cost</dt><dd class="tabular-nums">{{ $money($selected->unit_cost) }}</dd>
                        <dt class="text-muted">Starting date</dt><dd>{{ $selected->counts_from?->format('M j, Y') ?? 'From the start' }}</dd>
                        @unless ($canSetSrp)
                            <dt class="text-muted">SRP</dt><dd class="tabular-nums">{{ $money($selected->srp) }}</dd>
                        @endunless
                    </dl>
                    @if ($canSetSrp)
                        @php($srpBag = $errors->getBag("product{$selected->id}"))
                        <form method="POST" action="{{ route('settings.product-consumption.srp', $selected) }}" class="mt-4 flex items-end gap-2 border-t border-line pt-3">
                            @csrf @method('PATCH')
                            <label class="flex-1">
                                <span class="{{ $label }}">SRP <span class="font-normal text-muted">(₱ per unit)</span></span>
                                <input type="number" name="srp" min="0" step="0.01" placeholder="Not set"
                                       value="{{ $srpBag->any() ? old('srp', $selected->srp) : $selected->srp }}"
                                       aria-label="SRP of {{ $selected->name }}" class="{{ $input }} tabular-nums">
                            </label>
                            <button type="submit" class="h-10 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">Save</button>
                        </form>
                        @foreach ($srpBag->all() as $error)
                            <p role="alert" class="mt-1 text-xs text-coral-700">{{ $error }}</p>
                        @endforeach
                    @endif
                @endif
            </aside>
        @endif
    </div>
</x-layouts.settings>
