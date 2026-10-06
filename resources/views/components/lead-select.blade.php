@props(['lead', 'field', 'list', 'editable', 'placeholder' => '—', 'emptyNote' => null])

{{-- An auto-saving dropdown cell. Options come from config('segmentation.<list>'): key => [label, classes] or key => label. --}}
@php
    $options = collect(config("segmentation.{$list}"))->map(fn ($o) => is_array($o) ? $o : [$o, 'bg-[#e6e6e6] text-[#3d3d3d]']);
    $value = $lead->{$field};
    $classes = $value !== null && isset($options[$value]) ? $options[$value][1] : 'bg-canvas text-muted';
@endphp

@if ($editable)
    <form method="POST" action="{{ route('segmentation.update', $lead) }}" data-autosave>
        @csrf @method('PATCH')
        <select name="{{ $field }}" aria-label="{{ $attributes->get('aria-label') }}"
                data-colors='@json($options->map(fn ($o) => $o[1]))' data-empty-classes="bg-canvas text-muted"
                @disabled($options->isEmpty())
                @if ($options->isEmpty() && $emptyNote) title="{{ $emptyNote }}" @endif
                class="h-9 max-w-64 rounded-full border-0 px-3 text-xs font-semibold focus:ring-2 focus:ring-brand-200 focus:outline-none disabled:cursor-not-allowed disabled:opacity-60 {{ $classes }}">
            <option value="" class="bg-white text-ink">{{ $options->isEmpty() && $emptyNote ? $emptyNote : $placeholder }}</option>
            @foreach ($options as $key => [$label])
                <option value="{{ $key }}" class="bg-white text-ink" @selected((string) $value === (string) $key)>{{ $label }}</option>
            @endforeach
        </select>
    </form>
@elseif ($value !== null && isset($options[$value]))
    <span class="rounded-full px-3 py-1.5 text-xs font-semibold whitespace-nowrap {{ $classes }}">{{ $options[$value][0] }}</span>
@else
    <span class="text-xs text-muted">—</span>
@endif
