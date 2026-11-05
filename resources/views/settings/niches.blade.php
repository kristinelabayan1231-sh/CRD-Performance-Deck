@php
    $input = 'h-10 w-full min-w-0 rounded-lg border border-line bg-white px-3 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none';
    $check = 'size-4 rounded border-line text-brand-600 focus:ring-brand-200';
    $nicheOf = $niches->flatMap(fn ($niche) => $niche->cras->mapWithKeys(fn ($cra) => [$cra->id => $niche->name]));
@endphp

<x-layouts.settings title="Niche">
    @if ($errors->any())
        <div role="alert" class="mb-4 rounded-lg border border-coral/60 bg-coral/10 px-4 py-3 text-sm text-coral-700">{{ $errors->first() }}</div>
    @endif
    @foreach ($niches as $niche)
        @if ($errors->getBag("niche{$niche->id}")->any())
            <div role="alert" class="mb-4 rounded-lg border border-coral/60 bg-coral/10 px-4 py-3 text-sm text-coral-700">{{ $niche->name }}: {{ $errors->getBag("niche{$niche->id}")->first() }}</div>
        @endif
    @endforeach

    <section class="mb-4 rounded-xl bg-white p-4 shadow-sm">
        <h2 class="text-base font-semibold">Niche</h2>
        <ul class="mt-1 list-disc space-y-1 pl-5 text-sm text-muted">
            <li>A CRA in a niche gets only the leads of the niche's products in the Segmentation Tracker. A CRA in no niche gets every product.</li>
            <li>Their own <span class="font-semibold text-ink">High AOV and VIP customers</span> stay with them whatever the product.</li>
            <li>Saving applies from now on. Leads a CRA has already worked stay with them; untouched leads outside their niche move to a CRA whose niche has the product.</li>
        </ul>
        @if ($unclaimed->isNotEmpty())
            <p role="status" class="mt-3 rounded-lg border border-coral/60 bg-coral/10 px-3 py-2 text-sm text-coral-700">Every CRA is in a niche, but no niche has {{ $unclaimed->join(', ', ' and ') }}. Their leads wait unassigned until a niche includes them.</p>
        @endif
    </section>

    <div class="space-y-4">
        @foreach ($niches as $niche)
            @php($bag = $errors->getBag("niche{$niche->id}"))
            @php($picked = $bag->any() ? collect(old('products', []))->map(fn ($id) => (int) $id) : $niche->products->pluck('id'))
            @php($pickedCras = $bag->any() ? collect(old('cras', []))->map(fn ($id) => (int) $id) : $niche->cras->pluck('id'))
            <form method="POST" action="{{ route('settings.niches.update', $niche) }}" class="rounded-xl bg-white p-4 shadow-sm">
                @csrf
                @method('PUT')
                @include('settings._niche-fields', ['niche' => $niche, 'name' => $bag->any() ? old('name') : $niche->name])

                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <button type="submit" class="h-10 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white shadow-sm hover:bg-brand-700">Save niche</button>
                    <button type="submit" form="delete-niche-{{ $niche->id }}" class="h-10 rounded-lg border border-coral/60 px-3 text-sm font-medium text-coral-700 hover:bg-coral/10">Delete</button>
                    @if ($niche->editor)
                        <p class="text-xs text-muted">Last changed by {{ $niche->editor->displayName() }} on {{ $niche->updated_at->timezone(config('segmentation.timezone'))->format('M j, Y g:i A') }}.</p>
                    @endif
                </div>
            </form>
            <form id="delete-niche-{{ $niche->id }}" method="POST" action="{{ route('settings.niches.destroy', $niche) }}" class="hidden"
                  data-confirm="Delete the {{ $niche->name }} niche? Its CRAs get every product's leads again." onsubmit="return confirm(this.dataset.confirm)">
                @csrf
                @method('DELETE')
            </form>
        @endforeach

        @php($picked = $errors->any() ? collect(old('products', []))->map(fn ($id) => (int) $id) : collect())
        @php($pickedCras = $errors->any() ? collect(old('cras', []))->map(fn ($id) => (int) $id) : collect())
        <form method="POST" action="{{ route('settings.niches.store') }}" class="rounded-xl border border-dashed border-line bg-white p-4 shadow-sm">
            @csrf
            <h2 class="mb-3 text-base font-semibold">Add niche</h2>
            @include('settings._niche-fields', ['niche' => null, 'name' => $errors->any() ? old('name') : ''])

            <div class="mt-4">
                <button type="submit" class="h-10 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white shadow-sm hover:bg-brand-700">Add niche</button>
            </div>
        </form>
    </div>
</x-layouts.settings>
