@php($canEdit = auth()->user()->can('product_consumption.manage'))
@php($control = 'h-10 w-20 rounded-lg border border-line bg-white px-3 text-sm tabular-nums focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none disabled:bg-canvas disabled:text-muted')

<x-layouts.settings title="Age Bracket">
    @if ($errors->any())
        <div role="alert" class="mb-4 rounded-lg border border-coral/60 bg-coral/10 px-4 py-3 text-sm text-coral-700">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('settings.age-bracket.update') }}">
        @csrf
        @method('PUT')

        <section class="rounded-xl bg-white p-4 shadow-sm">
            <h2 class="text-base font-semibold">Target age bracket per product</h2>
            <p class="text-sm text-muted">The customer ages each product is meant for, to compare with the ages of the customers who bought it. Leave an end blank for no limit (e.g. 45 and up).</p>

            <ul class="mt-4 divide-y divide-line rounded-lg border border-line">
                @forelse ($products as $product)
                    <li class="flex flex-wrap items-center gap-x-4 gap-y-2 px-3 py-2">
                        <span class="min-w-0 flex-1 text-sm font-semibold">{{ $product->name }}</span>
                        <span class="flex items-center gap-2 text-sm">
                            <input type="number" name="brackets[{{ $product->id }}][min]" min="1" max="120" placeholder="Any"
                                   value="{{ old("brackets.{$product->id}.min", $product->age_min) }}" aria-label="{{ $product->name }} youngest age"
                                   @disabled(! $canEdit) class="{{ $control }}">
                            <span class="text-muted">to</span>
                            <input type="number" name="brackets[{{ $product->id }}][max]" min="1" max="120" placeholder="Any"
                                   value="{{ old("brackets.{$product->id}.max", $product->age_max) }}" aria-label="{{ $product->name }} oldest age"
                                   @disabled(! $canEdit) class="{{ $control }}">
                            <span class="text-muted">years old</span>
                        </span>
                    </li>
                @empty
                    <li class="px-3 py-4 text-sm text-muted">No products yet. Add them in Product Consumption.</li>
                @endforelse
            </ul>

            @if ($canEdit && $products->isNotEmpty())
                <div class="mt-4">
                    <button type="submit" class="h-10 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-700">Save</button>
                </div>
            @endif
        </section>
    </form>
</x-layouts.settings>
