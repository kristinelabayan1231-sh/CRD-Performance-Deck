<x-layouts.app title="Product Consumption">
    @include('settings._header')

    @php($canManage = auth()->user()->can('product_consumption.manage'))
    @php($input = 'h-11 w-full rounded-lg border border-line bg-white px-3 focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none')

    {{-- Add product --}}
    @if ($canManage)
        <section class="mb-8 rounded-xl bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold">Add product</h2>
            <p class="text-sm text-muted">Orders whose product name contains this name (or an extra keyword) are grouped under it.</p>
            <form method="POST" action="{{ route('settings.product-consumption.store') }}" class="mt-4 flex flex-col gap-4 md:flex-row md:items-end">
                @csrf
                <label class="flex-1">
                    <span class="mb-1 block text-sm font-medium">Product (keyword)</span>
                    <input type="text" name="name" value="{{ $errors->any() ? old('name') : '' }}" required maxlength="255" placeholder="e.g. Pterygium" class="{{ $input }}">
                </label>
                <label class="flex-1">
                    <span class="mb-1 block text-sm font-medium">Also matches <span class="font-normal text-muted">(optional, comma-separated)</span></span>
                    <input type="text" name="keywords" value="{{ $errors->any() ? old('keywords') : '' }}" maxlength="1000" placeholder="e.g. Clear Sight, Clearsite" class="{{ $input }}">
                </label>
                <button type="submit" class="h-11 rounded-lg bg-brand-600 px-5 font-semibold text-white shadow-sm transition hover:bg-brand-700">
                    Add product
                </button>
            </form>
            @if ($errors->any())
                <ul role="alert" class="mt-3 space-y-1 text-sm text-coral-700">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif

    {{-- Products --}}
    <section class="overflow-hidden rounded-xl bg-white shadow-sm">
        <div class="flex items-center justify-between border-b border-line px-6 py-4">
            <h2 class="text-lg font-semibold">Products</h2>
            <span class="text-sm text-muted">{{ $products->count() }} total</span>
        </div>

        @if ($products->isEmpty())
            <p class="px-6 py-10 text-center text-sm text-muted">No products yet.@if ($canManage) Add your first one above.@endif</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-left text-sm">
                    <thead class="bg-canvas/60 text-xs tracking-wide text-muted uppercase">
                        <tr>
                            <th class="px-6 py-3 font-semibold">Product</th>
                            <th class="px-6 py-3 font-semibold">Also matches</th>
                            <th class="px-6 py-3 font-semibold">Updated</th>
                            @if ($canManage)
                                <th class="px-6 py-3 text-right font-semibold">Actions</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($products as $product)
                            @php($bag = $errors->getBag("product{$product->id}"))
                            <tr class="align-middle">
                                @if ($canManage)
                                    <td class="min-w-64 px-6 py-3">
                                        <input form="product-{{ $product->id }}" type="text" name="name" required maxlength="255"
                                               value="{{ $bag->any() ? old('name', $product->name) : $product->name }}"
                                               aria-label="Name of {{ $product->name }}" class="{{ $input }} h-10">
                                        @foreach ($bag->all() as $error)
                                            <p role="alert" class="mt-1 text-xs text-coral-700">{{ $error }}</p>
                                        @endforeach
                                    </td>
                                    <td class="min-w-56 px-6 py-3">
                                        <input form="product-{{ $product->id }}" type="text" name="keywords" maxlength="1000" placeholder="—"
                                               value="{{ $bag->any() ? old('keywords', $product->keywords) : $product->keywords }}"
                                               aria-label="Extra keywords for {{ $product->name }}" class="{{ $input }} h-10">
                                    </td>
                                @else
                                    <td class="px-6 py-4 font-medium">{{ $product->name }}</td>
                                    <td class="px-6 py-4 text-muted">{{ $product->keywords ?: '—' }}</td>
                                @endif
                                <td class="px-6 py-3 text-muted">{{ $product->updated_at?->diffForHumans() }}</td>
                                @if ($canManage)
                                    <td class="px-6 py-3">
                                        <div class="flex justify-end gap-2">
                                            <form id="product-{{ $product->id }}" method="POST" action="{{ route('settings.product-consumption.update', $product) }}">
                                                @csrf @method('PATCH')
                                                <button type="submit" class="h-10 rounded-lg border border-line px-3 font-medium hover:border-brand-400 hover:text-brand-600">Save</button>
                                            </form>
                                            <form method="POST" action="{{ route('settings.product-consumption.destroy', $product) }}"
                                                  data-confirm="Delete {{ $product->name }}?" onsubmit="return confirm(this.dataset.confirm)">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="h-10 rounded-lg border border-coral/60 px-3 font-medium text-coral-700 hover:bg-coral/10">Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-layouts.app>
