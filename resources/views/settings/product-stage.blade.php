@php($canEdit = auth()->user()->can('product_stage.manage'))
@php([$testing, $existing] = $products->partition(fn ($product) => $product->is_testing))

<x-layouts.settings title="Testing Products">
    @if ($errors->any())
        <div role="alert" class="mb-4 rounded-lg border border-coral/60 bg-coral/10 px-4 py-3 text-sm text-coral-700">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('settings.product-stage.update') }}">
        @csrf
        @method('PUT')

        <section class="rounded-xl bg-white p-4 shadow-sm">
            <h2 class="text-base font-semibold">Testing products</h2>
            <p class="text-sm text-muted">Tick the products still being tested; the rest are existing products. A new product is set when it's added in Product Consumption, and can be changed here.</p>

            <div class="mt-4 grid gap-4 md:grid-cols-2">
                @foreach (['Testing' => $testing, 'Existing' => $existing] as $label => $group)
                    <div>
                        <p class="mb-2 flex items-center gap-2 text-sm font-semibold">
                            {{ $label }}
                            <span class="rounded-full bg-canvas px-2 py-0.5 text-xs font-bold text-muted tabular-nums">{{ $group->count() }}</span>
                        </p>
                        <ul class="divide-y divide-line rounded-lg border border-line">
                            @forelse ($group as $product)
                                <li>
                                    <label @class(['flex min-h-11 items-center gap-3 px-3 py-2 text-sm', 'cursor-pointer' => $canEdit])>
                                        <input type="checkbox" name="testing[]" value="{{ $product->id }}" @checked($product->is_testing) @disabled(! $canEdit)
                                               aria-label="{{ $product->name }} is a testing product" class="size-4 rounded border-line text-brand-600 focus:ring-brand-200">
                                        <span class="font-semibold">{{ $product->name }}</span>
                                    </label>
                                </li>
                            @empty
                                <li class="px-3 py-4 text-sm text-muted">None.</li>
                            @endforelse
                        </ul>
                    </div>
                @endforeach
            </div>

            @if ($canEdit && $products->isNotEmpty())
                <div class="mt-4">
                    <button type="submit" class="h-10 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-700">Save</button>
                </div>
            @endif
        </section>
    </form>
</x-layouts.settings>
