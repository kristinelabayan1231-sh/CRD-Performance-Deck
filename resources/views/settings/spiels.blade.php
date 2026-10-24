@php
    $field = 'w-full rounded-lg border border-line bg-white px-3 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none';
@endphp

<x-layouts.settings title="My Spiels">
    @if ($errors->any())
        <div role="alert" class="mb-4 rounded-lg border border-coral/60 bg-coral/10 px-4 py-3 text-sm text-coral-700">{{ $errors->first() }}</div>
    @endif

    <div class="flex flex-col gap-4 xl:flex-row xl:items-start">
        <section aria-labelledby="add-spiel-title" class="rounded-xl bg-white p-4 shadow-sm xl:w-96 xl:shrink-0">
            <h2 id="add-spiel-title" class="text-base font-semibold">Add a spiel</h2>
            <p class="text-sm text-muted">Only you see your spiels, in the Segmentation Tracker's Spiels panel.</p>
            <form method="POST" action="{{ route('settings.spiels.store') }}" class="mt-3 space-y-3" data-spiel-form>
                @csrf
                <label class="block text-sm font-medium">Spiel Title
                    <input type="text" name="title" value="{{ old('title') }}" maxlength="80" required placeholder="e.g. Reorder offer" class="{{ $field }} mt-1 h-11 font-normal">
                </label>
                <div class="text-sm font-medium">
                    <p class="mb-1">Spiel Content</p>
                    @include('settings._spiel-editor', ['value' => \App\Models\Spiel::sanitize(old('body', ''))])
                </div>
                <button type="submit" class="h-11 w-full rounded-lg bg-brand-600 px-5 font-semibold text-white shadow-sm transition hover:bg-brand-700">Save spiel</button>
            </form>
        </section>

        <section aria-labelledby="my-spiels-title" class="min-w-0 flex-1 overflow-hidden rounded-xl bg-white shadow-sm">
            <div class="border-b border-line px-4 py-4">
                <h2 id="my-spiels-title" class="text-base font-semibold">My spiels <span class="text-sm font-normal text-muted">· {{ $spiels->count() }}</span></h2>
                <p class="text-sm text-muted">Drag to reorder: the Spiels panel shows them in this order.</p>
            </div>
            <ul class="divide-y divide-line" data-spiel-list data-reorder-url="{{ route('settings.spiels.reorder') }}">
                @forelse ($spiels as $spiel)
                    <li class="flex items-start gap-3 px-4 py-3" data-spiel-id="{{ $spiel->id }}">
                        <span aria-hidden="true" data-spiel-handle class="cursor-grab pt-0.5 text-muted/50" title="Drag to reorder">
                            <svg class="size-4" fill="currentColor" viewBox="0 0 24 24"><circle cx="9" cy="6" r="1.6"/><circle cx="15" cy="6" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="9" cy="18" r="1.6"/><circle cx="15" cy="18" r="1.6"/></svg>
                        </span>
                        <details class="group min-w-0 flex-1">
                            <summary class="flex cursor-pointer list-none items-start gap-3 [&::-webkit-details-marker]:hidden">
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-semibold">{{ $spiel->title }}</span>
                                    <div class="spiel-rich mt-0.5 text-sm text-muted group-open:hidden">{!! $spiel->body !!}</div>
                                </span>
                                <span class="h-9 shrink-0 rounded-lg border border-line px-3 text-xs leading-9 font-semibold text-brand-700 group-open:hidden">Edit</span>
                            </summary>
                            <form method="POST" action="{{ route('settings.spiels.update', $spiel) }}" class="mt-2 space-y-2" data-spiel-form>
                                @csrf
                                @method('PUT')
                                <input type="text" name="title" value="{{ $spiel->title }}" maxlength="80" required aria-label="Spiel Title" class="{{ $field }} h-10">
                                @include('settings._spiel-editor', ['value' => \App\Models\Spiel::sanitize($spiel->body), 'rows' => 5])
                                <div class="flex gap-2">
                                    <button type="submit" class="h-9 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">Save</button>
                                    <button type="button" onclick="this.closest('details').open = false" class="h-9 rounded-lg border border-line px-4 text-sm font-medium hover:border-brand-400">Cancel</button>
                                </div>
                            </form>
                        </details>
                        <form method="POST" action="{{ route('settings.spiels.destroy', $spiel) }}" data-confirm="Delete the spiel “{{ $spiel->title }}”?" onsubmit="return confirm(this.dataset.confirm)">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="rounded-lg px-3 py-2 text-xs font-medium text-coral-700 hover:bg-coral/10">Delete</button>
                        </form>
                    </li>
                @empty
                    <li class="px-4 py-8 text-center text-sm text-muted">No spiels yet. Add your first one on the left.</li>
                @endforelse
            </ul>
        </section>
    </div>
</x-layouts.settings>
