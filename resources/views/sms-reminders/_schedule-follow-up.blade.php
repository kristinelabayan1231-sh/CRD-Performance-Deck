{{-- Schedule a follow-up text for a customer: a day (Tomorrow / In 3 days / In 7 days or any), the text (one of the CRA's
     templates or their default follow-up) and a note. Expects $phoneKey; $templates (optional) and $label. --}}
@php
    $today = \App\Support\WorkingDate::realToday();
    $formId = 'schedule-'.$phoneKey.'-'.($idSuffix ?? 'x');
@endphp
<details class="relative">
    <summary class="flex h-9 cursor-pointer list-none items-center rounded-lg border border-brand-200 bg-brand-50 px-3 text-xs font-semibold text-brand-700 hover:border-brand-400">{{ $label ?? 'Schedule follow-up' }}</summary>
    <form id="{{ $formId }}" method="POST" action="{{ route('sms-reminders.follow-ups.store', $phoneKey) }}"
          class="absolute right-0 z-20 mt-2 grid w-72 gap-3 rounded-xl border border-line bg-white p-4 text-left shadow-lg">
        @csrf
        <p class="text-sm font-semibold">Follow-up text</p>
        <div class="flex flex-wrap gap-1.5">
            @foreach (['Tomorrow' => 1, 'In 3 days' => 3, 'In 7 days' => 7] as $name => $days)
                <button type="button" onclick="this.form.day.value = '{{ $today->addDays($days)->toDateString() }}'"
                        class="rounded-lg border border-line px-2 py-1 text-xs font-semibold hover:border-brand-400 hover:text-brand-600">{{ $name }}</button>
            @endforeach
        </div>
        <label class="grid gap-1 text-xs font-semibold">
            Day
            <input type="date" name="day" required min="{{ $today->toDateString() }}" value="{{ $today->addDays(7)->toDateString() }}" class="h-9 rounded-lg border border-line px-2 text-sm font-normal">
        </label>
        @if (($templates ?? collect())->isNotEmpty())
            <label class="grid gap-1 text-xs font-semibold">
                Text
                <select name="template" class="h-9 rounded-lg border border-line px-2 text-sm font-normal">
                    <option value="">Their follow-up template, else the default</option>
                    @foreach ($templates as $template)
                        <option value="{{ $template->id }}">{{ $template->name }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        <label class="grid gap-1 text-xs font-semibold">
            Note for yourself
            <input type="text" name="note" maxlength="200" placeholder="e.g. call after payday" class="h-9 rounded-lg border border-line px-2 text-sm font-normal">
        </label>
        <p class="text-[0.6875rem] text-muted">Shows in Today's texts on that day and goes out like any text. Cancelled by itself if they reorder or reply STOP.</p>
        <button type="submit" class="h-9 rounded-lg bg-brand-600 text-sm font-semibold text-white hover:bg-brand-700">Schedule</button>
    </form>
</details>
