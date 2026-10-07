<x-layouts.app title="Working Date">
    @include('settings._header')

    @if ($errors->any())
        <div role="alert" class="mb-4 rounded-lg border border-coral/60 bg-coral/10 px-4 py-3 text-sm text-coral-700">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('settings.working-date.update') }}">
        @csrf
        @method('PUT')

        <section class="rounded-xl bg-white p-4 shadow-sm">
            <h2 class="text-base font-semibold">Working date</h2>
            <p class="text-sm text-muted">For working through an earlier month's leads. The app runs a fixed gap behind the real date: pick the day the team starts tomorrow, and from then on the working date moves forward one day with each real day. The dashboard, Segmentation Tracker (leads, backlog, automatic lead assignment), Weekly Segmentation, Segmentation Productivity and Conversion Breakdown all follow it. Leave blank to follow the real date.</p>

            @if ($workingDate)
                <p class="mt-3 text-sm">
                    <span class="font-semibold">Today ({{ $realToday->format('M j') }}) = {{ $workingDate->format('M j, Y') }}</span>
                    · Tomorrow ({{ $realToday->addDay()->format('M j') }}) = {{ $workingDate->addDay()->format('M j, Y') }}, and its leads are assigned automatically.
                </p>
            @endif

            <div class="mt-5 flex flex-wrap items-end gap-3">
                <label class="block">
                    <span class="mb-1 block text-sm font-semibold">Start of working date (tomorrow)</span>
                    <input type="date" name="start" max="{{ $realToday->addDay()->toDateString() }}" value="{{ old('start', $workingDate?->addDay()->toDateString()) }}"
                           class="h-10 rounded-lg border border-line bg-white px-3 text-sm tabular-nums focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
                </label>
                <button type="submit" class="h-10 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-700">Save</button>
                @if ($workingDate)
                    <button type="submit" name="start" value="" class="h-10 rounded-lg border border-line px-4 text-sm font-semibold text-muted transition hover:text-brand-600">Use real date</button>
                @endif
            </div>

            @if ($lastChange?->editor)
                <p class="mt-4 text-xs text-muted">Last changed by {{ $lastChange->editor->displayName() }} on {{ $lastChange->updated_at->timezone(config('segmentation.timezone'))->format('M j, Y g:i A') }}.</p>
            @endif
        </section>
    </form>
</x-layouts.app>
