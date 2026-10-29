{{-- One product group row: its name, its starting date, its pages as chips, and a picker for the pages not in a group. --}}
<div class="grid gap-3 px-4 py-3 md:grid-cols-[10rem_minmax(0,1fr)_auto] md:items-center" data-group>
    <div>
        <p class="font-semibold" data-group-title>{{ $name }}</p>
        <p class="text-xs text-muted"><span data-group-count>{{ count($pages) }}</span> <span data-group-rows>{{ count($pages) === 1 ? 'row' : 'rows' }}</span> per CRA</p>
        <label class="mt-1.5 block" title="PC Report and the Daily I.S. count this product from this day; blank = from the start">
            <span class="block text-[0.6875rem] font-semibold text-muted">Starting date</span>
            <input type="date" data-group-start value="{{ $startsOn ?? '' }}" aria-label="Starting date of {{ $name ?: 'this product' }}"
                   class="h-8 w-full rounded-lg border border-line bg-white px-2 text-xs focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
        </label>
    </div>
    <div class="flex flex-wrap gap-1.5" data-group-pages>
        @foreach ($pages as $page)
            <span class="inline-flex items-center gap-1 rounded-full border border-line bg-canvas/60 py-0.5 pr-1 pl-3 text-xs font-medium" data-page="{{ $page }}">
                {{ $page }}
                <button type="button" data-group-unpage class="grid size-5 place-items-center rounded-full text-muted hover:bg-coral/15 hover:text-coral-700" aria-label="Take {{ $page }} out of {{ $name }}">×</button>
            </span>
        @endforeach
        <span @class(['text-xs text-muted', 'hidden' => $pages !== []]) data-group-none>No pages yet</span>
    </div>
    <div class="flex gap-2">
        <label>
            <span class="sr-only">Add a page</span>
            <select data-group-page class="h-10 w-40 rounded-lg border border-line bg-white px-2.5 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
                <option value="">+ Add page</option>
            </select>
        </label>
        <button type="button" data-group-remove class="h-10 rounded-lg border border-coral/60 px-3 font-medium text-coral-700 hover:bg-coral/10">Remove</button>
    </div>
</div>
