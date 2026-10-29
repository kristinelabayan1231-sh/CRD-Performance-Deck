{{-- One call in the Call Recording column; expects $recording, $canHearAll and $tz from _recordings. A playable call opens the recording dialog. --}}
@php($when = $recording->called_at->timezone($tz)->format('M j, g:i A').' · '.\App\Models\CallRecording::formatDuration($recording->duration_seconds))
@if ($canHearAll || $recording->user_id === auth()->id())
    <button type="button" data-recording-url="{{ route('call-recordings.audio', $recording) }}" data-recording-title="{{ $lead->customer_name }}" data-recording-meta="{{ $when }}"
            aria-label="Play the call with {{ $lead->customer_name }}, {{ $when }}"
            class="flex w-fit items-center gap-1.5 rounded-full bg-brand-50 py-1 pr-2.5 pl-1 text-[0.6875rem] font-semibold text-brand-700 tabular-nums transition hover:bg-brand-100">
        <span aria-hidden="true" class="grid size-5 place-items-center rounded-full bg-brand-600 text-white">
            <svg class="size-2.5 translate-x-px" viewBox="0 0 24 24" fill="currentColor"><path d="M7 4.5v15l13-7.5z"/></svg>
        </span>
        {{ $when }}
    </button>
@else
    <p class="text-[0.6875rem] text-muted tabular-nums">{{ $when }}</p>
@endif
