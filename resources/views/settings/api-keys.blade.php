@php($tz = config('segmentation.timezone'))

<x-layouts.settings title="API Keys">
    @if ($errors->any())
        <div role="alert" class="mb-4 rounded-lg border border-coral/60 bg-coral/10 px-4 py-3 text-sm text-coral-700">{{ $errors->first() }}</div>
    @endif
    @if (session('status'))
        <div role="status" class="mb-4 rounded-lg border border-teal/50 bg-teal/10 px-4 py-3 text-sm text-teal-700">{{ session('status') }}</div>
    @endif

    @if (session('new_key'))
        <section role="status" class="mb-4 rounded-xl border border-brand-200 bg-brand-50 p-4 text-sm">
            <h2 class="text-base font-semibold">Key for {{ session('new_key')['name'] }}</h2>
            <p class="text-muted">Copy it now and send it to them privately: it is shown only once.</p>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <code class="rounded-lg border border-line bg-white px-3 py-2 font-mono text-sm break-all select-all">{{ session('new_key')['token'] }}</code>
                <button type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.textContent); this.textContent = 'Copied'"
                        class="h-9 rounded-lg border border-brand-200 bg-white px-3 text-xs font-semibold text-brand-700 hover:border-brand-400">Copy</button>
            </div>
        </section>
    @endif

    <section class="mb-4 rounded-xl bg-white p-4 shadow-sm">
        <h2 class="text-base font-semibold">Make a key</h2>
        <p class="text-sm text-muted">One key per team or system that reads CRD's figures, so each can be revoked on its own.</p>
        <form method="POST" action="{{ route('settings.api-keys.store') }}" class="mt-3 flex flex-wrap items-center gap-2">
            @csrf
            <input type="text" name="name" required maxlength="100" placeholder="Who it's for, e.g. Finance report" aria-label="Who the key is for"
                   class="h-11 w-72 rounded-lg border border-line bg-white px-3 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
            <button type="submit" class="h-11 rounded-lg bg-brand-600 px-5 font-semibold text-white shadow-sm transition hover:bg-brand-700">Make key</button>
        </form>
    </section>

    <section class="mb-4 overflow-hidden rounded-xl bg-white shadow-sm">
        <div class="border-b border-line px-4 py-4">
            <h2 class="text-base font-semibold">Keys</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[44rem] text-left text-sm">
                <thead class="bg-canvas/60 text-xs tracking-wide text-muted uppercase">
                    <tr>
                        <th class="px-4 py-3 font-semibold">For</th>
                        <th class="px-4 py-3 font-semibold">Key</th>
                        <th class="px-4 py-3 font-semibold">Made</th>
                        <th class="px-4 py-3 font-semibold">Last used</th>
                        <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($keys as $key)
                        <tr @class(['text-muted' => $key->revoked_at])>
                            <td class="px-4 py-3 font-medium">{{ $key->name }}</td>
                            <td class="px-4 py-3 font-mono text-xs">{{ $key->prefix }}…</td>
                            <td class="px-4 py-3 text-muted">{{ $key->created_at->timezone($tz)->format('M j, Y') }}{{ $key->creator ? ' · '.$key->creator->displayName() : '' }}</td>
                            <td class="px-4 py-3 text-muted">{{ $key->last_used_at?->timezone($tz)->format('M j, g:i A') ?? 'Never' }}</td>
                            <td class="px-4 py-3 text-right">
                                @if ($key->revoked_at)
                                    <span class="text-xs">Revoked {{ $key->revoked_at->timezone($tz)->format('M j, Y') }}</span>
                                @else
                                    <form method="POST" action="{{ route('settings.api-keys.destroy', $key) }}"
                                          onsubmit="return confirm(@js('Revoke the key for '.$key->name.'? Their report stops getting data until they have a new key.'))">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="h-9 rounded-lg border border-line px-3 text-xs font-semibold text-coral-700 transition hover:border-coral">Revoke</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-6 text-center text-muted">No keys yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="rounded-xl bg-white p-4 text-sm shadow-sm">
        <h2 class="text-base font-semibold">Retention daily figures</h2>
        <p class="mt-1 text-muted">Send the key as <code class="rounded bg-canvas px-1 py-0.5 text-xs text-ink">Authorization: Bearer …</code> (or <code class="rounded bg-canvas px-1 py-0.5 text-xs text-ink">X-Api-Key: …</code>). Up to {{ $maxDays }} days per call, 60 calls a minute. Dates are Philippine time, amounts in pesos.</p>
        <pre class="mt-3 overflow-x-auto rounded-lg bg-canvas px-3 py-2 font-mono text-xs text-ink">GET {{ $endpoint }}?from=2026-11-01&amp;to=2026-11-07</pre>

        <dl class="mt-4 grid gap-x-6 gap-y-2 sm:grid-cols-[12rem_1fr]">
            <dt class="font-mono text-xs font-semibold">leads</dt>
            <dd class="text-muted">Leads on that day's Segmentation Tracker list assigned to a CRA (the paired lead day when a Working Date is set). Leads added from POS sales are left out.</dd>
            <dt class="font-mono text-xs font-semibold">leadsContacted</dt>
            <dd class="text-muted">Those leads a CRA worked: a status, a date of contact, or marked processed.</dd>
            <dt class="font-mono text-xs font-semibold">orders · cancelledOrders</dt>
            <dd class="text-muted">CRD orders created that day, not canceled · canceled.</dd>
            <dt class="font-mono text-xs font-semibold">grossSales</dt>
            <dd class="text-muted">Every order's value, canceled ones too (as the Daily I.S. counts it). Shecom's amount once synced, else Pancake's.</dd>
            <dt class="font-mono text-xs font-semibold">cancelledSales</dt>
            <dd class="text-muted">Value of the canceled orders, by order date.</dd>
            <dt class="font-mono text-xs font-semibold">deliveredSales · returnedSales</dt>
            <dd class="text-muted">Delivered or payment collected · returning, returned or partially returned. By the order's date, with its status now.</dd>
            <dt class="font-mono text-xs font-semibold">byStatus</dt>
            <dd class="text-muted">Orders and sales for every Pancake status the day's orders have.</dd>
            <dt class="font-mono text-xs font-semibold">repeatCustomerOrders</dt>
            <dd class="text-muted">Orders (not canceled) from customers with a delivered order before that day in the Customer Database.</dd>
            <dt class="font-mono text-xs font-semibold">reactivatedCustomers</dt>
            <dd class="text-muted">Not tracked yet: always null.</dd>
            <dt class="font-mono text-xs font-semibold">agents</dt>
            <dd class="text-muted">The same figures per CRA.</dd>
        </dl>

        <h3 class="mt-5 font-semibold">Which orders are CRD's</h3>
        <p class="mt-1 text-muted">Orders a CRA made under their own Pancake account (or its other accounts in Settings → Pancake Accounts), plus orders the CVR Tracker counts for a CRA. Only orders with a CRD product, or only a TSD upsell product. Deleted orders are left out. Each order belongs to one CRA and to CRD only: an order the CVR Tracker moves to another CRA counts once, for that CRA.</p>
    </section>
</x-layouts.settings>
