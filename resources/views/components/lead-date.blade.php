@props(['lead', 'field', 'editable'])

{{-- An auto-saving date cell. --}}
@if ($editable)
    <form method="POST" action="{{ route('segmentation.update', $lead) }}" data-autosave>
        @csrf @method('PATCH')
        <input type="date" name="{{ $field }}" value="{{ $lead->{$field}?->toDateString() }}" aria-label="{{ $attributes->get('aria-label') }}"
               class="h-9 rounded-lg border border-line bg-white px-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
    </form>
@else
    <span class="whitespace-nowrap">{{ $lead->{$field}?->format('M j, Y') ?? '—' }}</span>
@endif
