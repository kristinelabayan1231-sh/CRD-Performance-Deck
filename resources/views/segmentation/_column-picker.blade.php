{{-- The columns pop-up (show / hide the tracker's columns, segmentation.js). Needs $optionalColumns; $pickerClass styles its button. --}}
<details class="relative shrink-0" data-column-picker>
    <summary title="Columns" aria-label="Columns"
             class="{{ $pickerClass ?? 'grid size-9 rounded-lg border border-line bg-white text-muted hover:border-brand-400 hover:text-brand-600' }} cursor-pointer list-none place-items-center [&::-webkit-details-marker]:hidden">
        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M9 4v16M15 4v16M4 4h16v16H4z"/></svg>
    </summary>
    <div class="absolute right-0 z-30 mt-2 w-72 rounded-xl border border-line bg-white p-3 shadow-lg">
        <p class="px-1 pb-2 text-xs text-muted">Status is always shown.</p>
        <div class="max-h-[60vh] overflow-y-auto">
        @php($leadColumns = auth()->user()->can('sms_reminders.view') ? config('segmentation.lead_columns') : \Illuminate\Support\Arr::except(config('segmentation.lead_columns'), 'sms'))
        @foreach ([...$leadColumns, ...$optionalColumns] as $key => $column)
            @if ($loop->index === count($leadColumns))
                <hr class="my-1.5 border-line">
            @endif
            <label @class(['flex items-center justify-between gap-2 rounded-lg px-1 py-1.5 text-sm', 'text-muted' => $column['coming_soon'] ?? false])>
                <span class="flex items-center gap-2">
                    <input type="checkbox" class="size-4 accent-brand-500" data-column-toggle="{{ $key }}"
                           @if ($column['coming_soon'] ?? false) disabled @else checked @endif>
                    {{ $column['label'] }}
                </span>
                @if ($column['coming_soon'] ?? false)
                    <span class="rounded-full bg-canvas px-2 py-0.5 text-xs font-semibold">Coming soon</span>
                @endif
            </label>
        @endforeach
        </div>
        <div class="mt-2 flex gap-2 border-t border-line pt-2">
            <button type="button" data-columns-all="show" class="flex-1 rounded-lg px-2 py-1.5 text-sm font-medium text-brand-600 hover:bg-brand-50">Show all</button>
            <button type="button" data-columns-all="main" title="Name, qty, product, contact #, text and status" class="flex-1 rounded-lg px-2 py-1.5 text-sm font-medium text-brand-600 hover:bg-brand-50">Main only</button>
            <button type="button" data-columns-all="hide" class="flex-1 rounded-lg px-2 py-1.5 text-sm font-medium text-brand-600 hover:bg-brand-50">Hide all</button>
        </div>
    </div>
</details>
