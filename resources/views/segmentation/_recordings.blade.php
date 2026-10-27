{{-- The lead's call recordings, latest first, earlier ones under "+ more". A recording opens the player dialog for its own
     CRA and for those who may hear every CRA's calls; others see its time and length. A link from the sheet shows as Open link. --}}
@php
    $recordings = $lead->callRecordings;
    $canHearAll = auth()->user()->can('call_recordings.view_all');
    $tz = config('segmentation.timezone');
@endphp

@if ($recordings->isEmpty() && ! $lead->call_recording_url)
    <span class="text-xs text-muted">—</span>
@else
    <div class="flex w-56 flex-col gap-1.5">
        @foreach ($recordings->take(1) as $recording)
            @include('segmentation._recording', ['recording' => $recording])
        @endforeach
        @if ($recordings->count() > 1)
            <details class="text-xs">
                <summary class="cursor-pointer font-semibold text-brand-600 hover:underline">+ {{ $recordings->count() - 1 }} more</summary>
                <div class="mt-1.5 flex flex-col gap-1.5">
                    @foreach ($recordings->skip(1) as $recording)
                        @include('segmentation._recording', ['recording' => $recording])
                    @endforeach
                </div>
            </details>
        @endif
        @if ($lead->call_recording_url && str_starts_with($lead->call_recording_url, 'http'))
            <a href="{{ $lead->call_recording_url }}" target="_blank" rel="noopener noreferrer" class="text-xs font-semibold text-brand-600 hover:underline">Open link</a>
        @elseif ($lead->call_recording_url)
            <span class="text-xs text-muted">{{ $lead->call_recording_url }}</span>
        @endif
    </div>
@endif
