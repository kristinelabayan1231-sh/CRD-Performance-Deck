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
            <p class="text-sm text-muted">The day the team is working on. While set, the dashboard, Segmentation Tracker (leads and backlog), Weekly Segmentation, Segmentation Productivity and Conversion Breakdown all show this day as today, and the hourly lead and Pancake syncs fetch this day. Move it forward when the team starts the next day. Leave blank to follow the real date ({{ $realToday->format('M j, Y') }}).</p>

            <div class="mt-5 flex flex-wrap items-end gap-3">
                <label class="block">
                    <span class="mb-1 block text-sm font-semibold">Working date</span>
                    <input type="date" name="working_date" max="{{ $realToday->toDateString() }}" value="{{ old('working_date', $workingDate?->toDateString()) }}"
                           class="h-10 rounded-lg border border-line bg-white px-3 text-sm tabular-nums focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
                </label>
                <button type="submit" class="h-10 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-700">Save</button>
                @if ($workingDate)
                    <button type="submit" name="working_date" value="" class="h-10 rounded-lg border border-line px-4 text-sm font-semibold text-muted transition hover:text-brand-600">Use real date</button>
                @endif
            </div>

            @if ($lastChange?->editor)
                <p class="mt-4 text-xs text-muted">Last changed by {{ $lastChange->editor->displayName() }} on {{ $lastChange->updated_at->timezone(config('segmentation.timezone'))->format('M j, Y g:i A') }}.</p>
            @endif
        </section>
    </form>
</x-layouts.app>
