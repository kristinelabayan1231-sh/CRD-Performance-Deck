{{-- One niche's name, products and CRAs: $niche (null when adding), $name, $picked, $pickedCras, $products, $cras, $nicheOf, $input, $check. --}}
@php($id = $niche?->id ?? 'new')
<label class="block max-w-sm">
    <span class="mb-1 block text-sm font-semibold">Niche name</span>
    <input type="text" name="name" value="{{ $name }}" maxlength="100" required placeholder="e.g. Eye care" class="{{ $input }}">
</label>

<fieldset class="mt-4">
    <legend class="mb-1 text-sm font-semibold">Products</legend>
    <div class="grid gap-x-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($products as $product)
            <label class="flex min-h-10 cursor-pointer items-center gap-2 text-sm">
                <input type="checkbox" name="products[]" value="{{ $product->id }}" @checked($picked->contains($product->id)) class="{{ $check }}">
                <span>{{ $product->name }}</span>
            </label>
        @endforeach
    </div>
</fieldset>

<fieldset class="mt-4">
    <legend class="mb-1 text-sm font-semibold">CRAs</legend>
    <div class="grid gap-x-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($cras as $cra)
            @php($elsewhere = $nicheOf[$cra->id] ?? null)
            <label class="flex min-h-10 cursor-pointer items-center gap-2 text-sm">
                <input type="checkbox" name="cras[]" value="{{ $cra->id }}" @checked($pickedCras->contains($cra->id)) class="{{ $check }}">
                <span>{{ $cra->displayName() }}</span>
                @if ($elsewhere && $elsewhere !== $niche?->name)
                    <span class="text-xs text-muted">in {{ $elsewhere }}; saving moves them here</span>
                @endif
            </label>
        @endforeach
    </div>
</fieldset>
