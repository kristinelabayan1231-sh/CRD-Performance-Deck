{{-- A cold lead's contact attempts: the Attempt button (logs today's, or takes it back; segmentation.js) with how many are left.
     On every row this user may edit, hidden until the lead is tagged cold, so picking a cold tag shows it straight away. --}}
@php($cold = $lead->isCold())
@php($left = $lead->attemptsLeft())
@php($today = $lead->attempts->contains(fn ($attempt) => $attempt->attempted_on->isSameDay(\App\Support\WorkingDate::realToday())))
@php($history = \App\Http\Controllers\LeadAttemptController::history($lead))

@if ($canEdit)
    <button type="button" data-attempt data-store-url="{{ route('segmentation.attempts.store', $lead) }}" data-undo-url="{{ route('segmentation.attempts.destroy', $lead) }}"
            data-cold-tags='@json(config('segmentation.cold_tags'))' data-left="{{ $left }}" aria-pressed="{{ $today ? 'true' : 'false' }}"
            title="{{ $history ? 'Attempts: '.$history : 'No attempts yet' }}{{ $today ? ' · click to take back today\'s' : '' }}"
            @disabled(! $today && $left === 0) @unless ($cold) hidden @endunless
            class="flex h-7 shrink-0 items-center gap-1 rounded-full bg-sky/15 px-2.5 text-xs font-semibold whitespace-nowrap text-sky-800 transition hover:bg-sky/25 disabled:cursor-not-allowed disabled:bg-canvas disabled:text-muted aria-pressed:bg-teal/15 aria-pressed:text-teal-700">
        <span data-attempt-label>{{ $today ? '✓ Tried today' : ($left ? 'Log attempt' : 'No attempts left') }}</span>
        <span data-attempt-left class="tabular-nums">{{ $left ? '· '.$left.' left' : '' }}</span>
    </button>
@elseif ($cold)
    <span title="{{ $history ?: 'No attempts yet' }}" class="rounded-full bg-canvas px-2.5 py-1 text-xs font-semibold whitespace-nowrap text-muted">{{ $left ? $left.' '.Str::plural('attempt', $left).' left' : 'No attempts left' }}</span>
@endif
