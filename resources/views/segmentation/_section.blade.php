{{-- One list of leads (Pending or Catered), 5 per page. Pending has a coral header, Catered a teal one. --}}
@php($visibleOptional = collect($optionalColumns)->reject(fn ($c) => $c['coming_soon'] ?? false))
@php($pending = $id === 'unprocessed')

<x-panel :title="$title" :accent="$pending ? 'coral' : 'teal'" :icon="$pending ? 'clock' : 'check'" :title-id="$id.'-title'"
         :tinted="false" body-class="" data-lead-section="{{ $id }}" class="flex flex-col">
    <x-slot:badges>
        <span @class(['rounded-full bg-white px-2 py-0.5 text-xs font-bold tabular-nums shadow-sm', 'text-coral-700' => $pending, 'text-teal-700' => ! $pending])>{{ number_format($leads->total()) }}</span>
        <p class="hidden text-xs text-muted sm:block">{{ $hint }}</p>
    </x-slot:badges>
    @if (! request()->boolean('full'))
        <x-slot:actions>
            <button type="button" title="Full view" aria-label="Open {{ $title }} in full view"
                    data-expand-title="{{ $title }}" data-expand-src="{{ request()->fullUrlWithQuery(['show' => $id, 'full' => 1, 'page' => null, 'processed_page' => null]) }}"
                    class="grid size-8 place-items-center rounded-lg bg-white/70 text-muted hover:bg-white hover:text-brand-600">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14 4h6v6M10 20H4v-6M20 4l-7 7M4 20l7-7"/></svg>
            </button>
        </x-slot:actions>
    @endif

    @if ($leads->isEmpty())
        <p class="px-4 py-10 text-center text-sm text-muted">{{ $empty }}</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-max min-w-full text-left text-sm">
                <thead class="bg-[#f7f4f8] text-xs tracking-wide text-muted uppercase">
                    <tr>
                        <th class="sticky left-0 z-10 bg-[#f7f4f8] px-4 py-3 font-semibold">Customer Name</th>
                        <th class="px-4 py-3 text-right font-semibold">Qty</th>
                        <th class="px-4 py-3 font-semibold">Product</th>
                        <th class="px-4 py-3 font-semibold">Contact #</th>
                        <th class="px-4 py-3 font-semibold">Delivered Date</th>
                        <th class="bg-[#fff4d6] px-4 py-3 text-center font-semibold text-[#7a5a00]">Days since Delivered</th>
                        <th class="px-4 py-3 font-semibold">Est. Out of Stock</th>
                        <th class="px-4 py-3 font-semibold">Recommended Replenishment Day</th>
                        <th class="px-4 py-3 font-semibold">Type</th>
                        <th class="px-4 py-3 font-semibold">Assigned to</th>
                        <th class="px-4 py-3 font-semibold">Status</th>
                        @foreach ($visibleOptional as $key => $column)
                            <th data-col="{{ $key }}" class="bg-[#e3f1ea] px-4 py-3 font-semibold text-[#1d5b45]">{{ $column['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($leads as $lead)
                        @include('segmentation._row', ['lead' => $lead, 'backlogRow' => false])
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($leads->hasPages())
            <div class="shrink-0 border-t border-line px-4 py-2">{{ $leads->links() }}</div>
        @endif
    @endif
</x-panel>
