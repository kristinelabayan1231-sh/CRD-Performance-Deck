<x-layouts.settings title="Daily I.S.">
    @if ($errors->any())
        <div role="alert" class="mb-4 rounded-lg border border-coral/60 bg-coral/10 px-4 py-3 text-sm text-coral-700">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('settings.daily-is.update') }}">
        @csrf
        @method('PUT')

        <section class="rounded-xl bg-white p-4 shadow-sm">
            <h2 class="text-base font-semibold">Deactivated products</h2>
            <p class="text-sm text-muted">A deactivated product doesn't show in the Daily I.S. on a day it has no sales. On a day it has sales, it shows with a "Deactivated" label.</p>

            <ul class="mt-4 divide-y divide-line rounded-lg border border-line">
                @forelse ($products as $product)
                    <li>
                        <label class="flex min-h-11 cursor-pointer items-center gap-3 px-3 py-2 text-sm">
                            <input type="checkbox" name="deactivated[]" value="{{ $product['group'] }}" @checked($product['deactivated'])
                                   class="size-4 rounded border-line text-brand-600 focus:ring-brand-200">
                            <span class="font-semibold">{{ $product['name'] }}</span>
                            @if ($product['name'] !== $product['group'])
                                <span class="text-xs text-muted">{{ $product['group'] }}</span>
                            @endif
                            @if ($product['deactivated'])
                                <span class="ml-auto rounded-full bg-coral/15 px-2 py-0.5 text-xs font-semibold text-coral-700">Deactivated</span>
                            @endif
                        </label>
                    </li>
                @empty
                    <li class="px-3 py-4 text-sm text-muted">No product groups yet. Add them in Pancake Pages.</li>
                @endforelse
            </ul>

            <div class="mt-4 flex items-center gap-3">
                <button type="submit" class="h-10 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-700">Save</button>
                @if ($lastChange?->editor)
                    <p class="text-xs text-muted">Last changed by {{ $lastChange->editor->displayName() }} on {{ $lastChange->updated_at->timezone(config('segmentation.timezone'))->format('M j, Y g:i A') }}.</p>
                @endif
            </div>
        </section>
    </form>
</x-layouts.settings>
