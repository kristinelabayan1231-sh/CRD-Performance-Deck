@php($control = 'h-10 rounded-lg border border-line bg-white px-3 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none')

<x-layouts.settings title="SMS Reminders">
    @if ($errors->any())
        <div role="alert" class="mb-4 rounded-lg border border-coral/60 bg-coral/10 px-4 py-3 text-sm text-coral-700">{{ $errors->first() }}</div>
    @endif
    @if (session('status'))
        <div role="status" class="mb-4 rounded-lg border border-teal/50 bg-teal/10 px-4 py-3 text-sm text-teal-700">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('settings.sms-reminders.update') }}" class="mb-4 space-y-4 rounded-xl bg-white p-4 shadow-sm">
        @csrf @method('PUT')
        <div>
            <h2 class="text-base font-semibold">Restock texts</h2>
            <p class="text-sm text-muted">Every morning at 6:30 the app lists the customers whose product runs out in {{ config('segmentation.replenishment_days_before') }} days. Their texts go out from their own CRA's phone (MacroDroid), one a minute, only {{ $windowsLabel }}; what's left after the last window waits for the next day.</p>
        </div>
        <label class="flex items-center gap-2.5 text-sm font-semibold">
            <input type="hidden" name="enabled" value="0">
            <input type="checkbox" name="enabled" value="1" @checked($enabled) class="size-4.5 rounded accent-brand-600">
            Send restock texts
        </label>
        <div class="flex flex-wrap items-end gap-4">
            <label class="flex flex-col gap-1.5 text-sm font-semibold">
                Automatic send time
                <input type="time" name="send_time" value="{{ $sendTime }}" required class="{{ $control }} font-normal">
            </label>
            <label class="flex flex-col gap-1.5 text-sm font-semibold">
                Most texts per phone a day
                <input type="number" name="daily_cap" value="{{ $dailyCap }}" min="1" max="1000" required class="{{ $control }} w-36 font-normal">
            </label>
        </div>
        <fieldset class="flex flex-wrap items-end gap-4">
            <legend class="mb-1.5 text-sm font-semibold">Sending windows</legend>
            @foreach (['Morning', 'Afternoon'] as $index => $name)
                @php($window = $windows[$index] ?? ['from' => '', 'until' => ''])
                <div class="flex flex-col gap-1.5">
                    <span class="text-xs font-semibold tracking-wide text-muted uppercase">{{ $name }}{{ $index ? ' (optional)' : '' }}</span>
                    <div class="flex items-center gap-1.5">
                        <input type="time" name="windows[{{ $index }}][from]" value="{{ old("windows.$index.from", $window['from']) }}" @required($index === 0) aria-label="{{ $name }} from" class="{{ $control }}">
                        <span class="text-sm text-muted">to</span>
                        <input type="time" name="windows[{{ $index }}][until]" value="{{ old("windows.$index.until", $window['until']) }}" @required($index === 0) aria-label="{{ $name }} until" class="{{ $control }}">
                    </div>
                </div>
            @endforeach
            <button type="submit" class="h-10 rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white hover:bg-brand-700">Save</button>
        </fieldset>
        <p class="text-xs text-muted">The send time applies to CRAs who turned on "Send automatically every day" on their SMS Reminders page; their texts start in the next window from then. One text a minute means a 2-hour window sends up to 120 per phone. Keep the daily limit low (about 200) so the telco doesn't flag a SIM as spam. Leave the afternoon times blank for one window only.</p>
    </form>

    <section class="mb-4 overflow-hidden rounded-xl bg-white shadow-sm">
        <div class="border-b border-line px-4 py-4">
            <h2 class="text-base font-semibold">CRA phones</h2>
            <p class="text-sm text-muted">Uses each CRA's phone link and upload token from <a href="{{ route('settings.call-recordings.index') }}" class="font-semibold text-brand-600 hover:underline">Settings → Call Recordings</a>. Each phone needs two MacroDroid macros:</p>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-muted">
                <li><span class="font-semibold text-ink">Send reminder</span>: Webhook trigger with the identifier <code class="rounded bg-canvas px-1 py-0.5 text-xs text-ink">{{ config('sms_reminders.webhook_identifier') }}</code>, then Send SMS to <code class="rounded bg-canvas px-1 py-0.5 text-xs text-ink">number</code> with <code class="rounded bg-canvas px-1 py-0.5 text-xs text-ink">message</code>.</li>
                <li><span class="font-semibold text-ink">Forward replies</span>: SMS Received trigger, then HTTP Request <code class="rounded bg-canvas px-1 py-0.5 text-xs text-ink">POST {{ $replyUrl }}</code> with <code class="rounded bg-canvas px-1 py-0.5 text-xs text-ink">number</code> and <code class="rounded bg-canvas px-1 py-0.5 text-xs text-ink">message</code>, header <code class="rounded bg-canvas px-1 py-0.5 text-xs text-ink">Authorization: Bearer &lt;upload token&gt;</code>.</li>
            </ul>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[44rem] text-left text-sm">
                <thead class="bg-canvas/60 text-xs tracking-wide text-muted uppercase">
                    <tr>
                        <th class="px-4 py-3 font-semibold">CRA</th>
                        <th class="px-4 py-3 font-semibold">Send reminder link</th>
                        <th class="px-4 py-3 font-semibold">Upload token</th>
                        <th class="px-4 py-3 font-semibold">Automatic sending</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($cras as $row)
                        <tr>
                            <td class="px-4 py-3 font-medium">{{ $row['cra']->displayName() }}</td>
                            <td class="px-4 py-3">
                                @if ($row['link'])
                                    <code class="text-xs break-all">{{ $row['link'] }}</code>
                                @else
                                    <span class="text-coral-700">No phone link</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">{{ $row['hasToken'] ? 'Set' : 'Not made yet' }}</td>
                            <td class="px-4 py-3">{{ $row['cra']->sms_auto_send ? 'On' : 'Off' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <form method="POST" action="{{ route('settings.sms-reminders.test') }}" class="space-y-3 rounded-xl bg-white p-4 shadow-sm">
        @csrf
        <div>
            <h2 class="text-base font-semibold">Send a test text</h2>
            <p class="text-sm text-muted">Sends one text from a CRA's phone to the number you type, to check the "Send reminder" macro. Reply YES to it to check the "Forward replies" macro.</p>
        </div>
        <div class="flex flex-wrap items-end gap-3">
            <label class="flex flex-col gap-1.5 text-sm font-semibold">
                From
                <select name="cra" required class="{{ $control }} font-normal">
                    @foreach ($cras as $row)
                        <option value="{{ $row['cra']->id }}" @selected((int) old('cra') === $row['cra']->id)>{{ $row['cra']->displayName() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1.5 text-sm font-semibold">
                To number
                <input type="tel" name="number" value="{{ old('number') }}" required placeholder="0917 123 4567" class="{{ $control }} font-normal">
            </label>
            <button type="submit" class="h-10 rounded-lg border border-brand-200 bg-white px-4 text-sm font-semibold text-brand-700 hover:border-brand-400">Send test text</button>
        </div>
    </form>
</x-layouts.settings>
