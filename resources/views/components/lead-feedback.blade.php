@props(['lead', 'editable', 'action' => null])

{{-- Customer's Feedback cell: several choices can be ticked, auto-saved to $action, else the lead's update route. The main
     choice shows first (HasFeedbacks). The ticks open in a pop-up so the table's scroll area doesn't clip them (segmentation.js). --}}
@php
    $options = collect(config('segmentation.feedback'));
    $picked = array_values(array_filter($lead->feedbackKeys(), fn (string $key) => isset($options[$key])));
    $badgeClass = 'rounded-full px-2.5 py-1 text-xs font-semibold whitespace-nowrap';
    $menuId = 'feedback-'.\Illuminate\Support\Str::random(8);
@endphp

@if ($editable)
    <form method="POST" action="{{ $action ?? route('segmentation.update', $lead) }}" data-autosave data-feedback-form>
        @csrf @method('PATCH')
        {{-- Always sent, so unticking the last choice clears them --}}
        <input type="hidden" name="feedbacks[]" value="">
        <button type="button" popovertarget="{{ $menuId }}" aria-label="{{ $attributes->get('aria-label') }}" @disabled($options->isEmpty())
                @if ($options->isEmpty()) title="Options coming soon" @endif
                class="flex min-h-9 max-w-72 flex-wrap items-center gap-1 rounded-2xl bg-canvas px-2 py-1 text-left focus:ring-2 focus:ring-brand-200 focus:outline-none disabled:cursor-not-allowed disabled:opacity-60">
            <span data-feedback-badges class="flex flex-wrap gap-1">
                @forelse ($picked as $key)
                    <span class="{{ $badgeClass }} {{ $options[$key][1] }}">{{ $options[$key][0] }}</span>
                @empty
                    <span class="px-1 text-xs font-semibold text-muted">—</span>
                @endforelse
            </span>
            <svg class="size-3.5 shrink-0 text-muted" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/></svg>
        </button>
        <div id="{{ $menuId }}" popover data-feedback-menu class="m-0 w-64 rounded-lg border border-line bg-white p-1.5 text-ink shadow-lg">
            <p class="px-2 pt-1 pb-1.5 text-[11px] text-muted">Tick every one that applies.</p>
            @foreach ($options as $key => [$label, $classes])
                <label class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 hover:bg-canvas">
                    <input type="checkbox" name="feedbacks[]" value="{{ $key }}" @checked(in_array($key, $picked, true)) class="size-4 accent-brand-500">
                    <span class="{{ $badgeClass }} {{ $classes }}">{{ $label }}</span>
                </label>
            @endforeach
        </div>
    </form>
@elseif ($picked)
    <span class="flex max-w-72 flex-wrap gap-1">
        @foreach ($picked as $key)
            <span class="{{ $badgeClass }} {{ $options[$key][1] }}">{{ $options[$key][0] }}</span>
        @endforeach
    </span>
@else
    <span class="text-xs text-muted">—</span>
@endif
