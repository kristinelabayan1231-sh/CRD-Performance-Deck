{{-- One call in the Call Recording column; expects $recording, $canHearAll and $tz from _recordings. --}}
<div>
    <p class="text-[11px] text-muted tabular-nums">
        {{ $recording->called_at->timezone($tz)->format('M j, g:i A') }} · {{ \App\Models\CallRecording::formatDuration($recording->duration_seconds) }}
    </p>
    @if ($canHearAll || $recording->user_id === auth()->id())
        {{-- Loads only when played: the audio comes from Google Drive through the app --}}
        <audio controls preload="none" class="h-8 w-full" src="{{ route('call-recordings.audio', $recording) }}" aria-label="Recording of the call with {{ $lead->customer_name }}, {{ $recording->called_at->timezone($tz)->format('M j, g:i A') }}"></audio>
    @endif
</div>
