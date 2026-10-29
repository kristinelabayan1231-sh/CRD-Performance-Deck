@php($tz = config('segmentation.timezone'))

<x-layouts.settings title="Call Recordings">
    @if ($errors->any())
        <div role="alert" class="mb-4 rounded-lg border border-coral/60 bg-coral/10 px-4 py-3 text-sm text-coral-700">{{ $errors->first() }}</div>
    @endif
    @if (session('status'))
        <div role="status" class="mb-4 rounded-lg border border-teal/50 bg-teal/10 px-4 py-3 text-sm text-teal-700">{{ session('status') }}</div>
    @endif

    <section class="mb-4 rounded-xl bg-white p-4 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="text-base font-semibold">Google Drive</h2>
                <p class="text-sm text-muted">Where the recordings are kept; the database keeps only each call's details. The app sees only the folder it makes, nothing else in the Drive.</p>
                @if ($driveConnected)
                    <p class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                        <span aria-hidden="true" class="size-2 rounded-full bg-teal"></span>
                        <span class="font-semibold">Connected as {{ $driveEmail }}</span>
                        @if ($folderUrl)
                            · <a href="{{ $folderUrl }}" target="_blank" rel="noopener" class="font-semibold text-brand-600 hover:underline">Open the folder</a>
                        @endif
                    </p>
                @endif
            </div>
            <a href="{{ route('settings.call-recordings.drive-connect') }}" class="grid h-11 place-items-center rounded-lg bg-brand-600 px-5 font-semibold text-white shadow-sm transition hover:bg-brand-700">
                {{ $driveConnected ? 'Connect again' : 'Connect Google Drive' }}
            </a>
        </div>
    </section>

    @if (session('new_token'))
        <section role="status" class="mb-4 rounded-xl border border-brand-200 bg-brand-50 p-4 text-sm">
            <h2 class="text-base font-semibold">{{ session('new_token')['cra'] }}'s upload token</h2>
            <p class="text-muted">Copy it into MacroDroid on {{ session('new_token')['cra'] }}'s phone now: it is shown only once. Their old token no longer works.</p>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <code class="rounded-lg border border-line bg-white px-3 py-2 font-mono text-sm break-all select-all">{{ session('new_token')['token'] }}</code>
                <button type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.textContent); this.textContent = 'Copied'"
                        class="h-9 rounded-lg border border-brand-200 bg-white px-3 text-xs font-semibold text-brand-700 hover:border-brand-400">Copy</button>
            </div>
        </section>
    @endif

    <section class="overflow-hidden rounded-xl bg-white shadow-sm">
        <div class="border-b border-line px-4 py-4">
            <h2 class="text-base font-semibold">CRA phones</h2>
            <p class="text-sm text-muted">The <span class="font-semibold text-ink">phone link</span> is the link of the phone's MacroDroid Webhook trigger: with it, the Call button in the Segmentation Tracker dials from that CRA's phone.</p>
            <p class="mt-1 text-sm text-muted">A <span class="font-semibold text-ink">shared phone</span> is used by another CRA for part of the day: its calls in those hours are saved under that CRA (in the app and the Drive file name). The phone keeps its owner's token.</p>
            <p class="mt-1 text-sm text-muted">Each phone uploads to <code class="rounded bg-canvas px-1 py-0.5 text-xs text-ink">POST {{ $uploadUrl }}</code> with its own token as <code class="rounded bg-canvas px-1 py-0.5 text-xs text-ink">Authorization: Bearer …</code>, so each call goes to the right CRA.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[80rem] text-left text-sm">
                <thead class="bg-canvas/60 text-xs tracking-wide text-muted uppercase">
                    <tr>
                        <th class="px-4 py-3 font-semibold">CRA</th>
                        <th class="px-4 py-3 font-semibold">Token</th>
                        <th class="px-4 py-3 font-semibold">Phone link</th>
                        <th class="px-4 py-3 font-semibold">Shared phone</th>
                        <th class="px-4 py-3 font-semibold">Last upload</th>
                        <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($cras as $cra)
                        <tr>
                            <td class="px-4 py-3 font-medium">
                                <span class="flex items-center gap-2"><x-avatar :user="$cra" class="size-7" />{{ $cra->displayName() }}</span>
                            </td>
                            <td class="px-4 py-3 text-muted">
                                {{ $cra->recording_token_created_at ? 'Created '.$cra->recording_token_created_at->timezone($tz)->format('M j, Y') : 'None yet' }}
                            </td>
                            <td class="px-4 py-3">
                                <form method="POST" action="{{ route('settings.call-recordings.phone', $cra) }}" class="flex items-center gap-2">
                                    @csrf
                                    @method('PUT')
                                    <input type="url" name="call_webhook_url" value="{{ $cra->call_webhook_url }}" placeholder="https://trigger.macrodroid.com/…"
                                           aria-label="{{ $cra->displayName() }}'s phone link"
                                           class="h-9 w-64 rounded-lg border border-line bg-white px-2.5 text-xs focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
                                    <button type="submit" class="h-9 rounded-lg border border-line px-3 text-xs font-semibold text-brand-700 transition hover:border-brand-400">Save</button>
                                </form>
                            </td>
                            <td class="px-4 py-3">
                                <form method="POST" action="{{ route('settings.call-recordings.shared-phone', $cra) }}" class="flex items-center gap-1.5">
                                    @csrf
                                    @method('PUT')
                                    <select name="shared_phone_cra_id" aria-label="Who else uses {{ $cra->displayName() }}'s phone"
                                            class="h-9 w-32 rounded-lg border border-line bg-white px-2 text-xs focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
                                        <option value="">Not shared</option>
                                        @foreach ($cras->except($cra->id) as $other)
                                            <option value="{{ $other->id }}" @selected($cra->shared_phone_cra_id === $other->id)>{{ $other->displayName() }}</option>
                                        @endforeach
                                    </select>
                                    <input type="time" name="shared_phone_from" value="{{ $cra->shared_phone_from ?? '06:00' }}" aria-label="Shared from"
                                           class="h-9 w-[6.5rem] rounded-lg border border-line bg-white px-2 text-xs focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
                                    <span class="text-xs text-muted">to</span>
                                    <input type="time" name="shared_phone_until" value="{{ $cra->shared_phone_until ?? '15:00' }}" aria-label="Shared until"
                                           class="h-9 w-[6.5rem] rounded-lg border border-line bg-white px-2 text-xs focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
                                    <button type="submit" class="h-9 rounded-lg border border-line px-3 text-xs font-semibold text-brand-700 transition hover:border-brand-400">Save</button>
                                </form>
                            </td>
                            <td class="px-4 py-3 text-muted">
                                {{ $cra->call_recordings_max_created_at ? \Carbon\CarbonImmutable::parse($cra->call_recordings_max_created_at)->timezone($tz)->format('M j, g:i A') : '—' }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <form method="POST" action="{{ route('settings.call-recordings.token', $cra) }}"
                                      @if ($cra->recording_token_created_at) onsubmit="return confirm(@js('Make a new token for '.$cra->displayName().'? Their phone stops uploading until the new one is in MacroDroid.'))" @endif>
                                    @csrf
                                    <button type="submit" class="h-9 rounded-lg border border-line px-3 text-xs font-semibold text-brand-700 transition hover:border-brand-400">
                                        {{ $cra->recording_token_created_at ? 'New token' : 'Create token' }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
</x-layouts.settings>
