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
            <h2 class="text-base font-semibold">Key for {{ session('new_key')['name'] }} <span class="text-sm font-normal text-muted">· reads {{ session('new_key')['scope'] ?? '' }}</span></h2>
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
        <p class="text-sm text-muted">One key per team or system that reads CRD's figures, so each can be revoked on its own. A key reads one thing only: an MC / salaries key opens payroll and nothing else, and no other key opens payroll.</p>
        <form method="POST" action="{{ route('settings.api-keys.store') }}" class="mt-3 flex flex-wrap items-center gap-2">
            @csrf
            <input type="text" name="name" required maxlength="100" placeholder="Who it's for, e.g. Finance report" aria-label="Who the key is for"
                   class="h-11 w-72 rounded-lg border border-line bg-white px-3 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
            <select name="scope" required aria-label="What the key may read"
                    class="h-11 rounded-lg border border-line bg-white px-3 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none">
                @foreach (\App\Models\ApiKey::SCOPES as $value => $label)
                    <option value="{{ $value }}" @selected(old('scope', \App\Models\ApiKey::RETENTION) === $value)>{{ $label }}</option>
                @endforeach
            </select>
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
                        <th class="px-4 py-3 font-semibold">Reads</th>
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
                            <td class="px-4 py-3">
                                <span @class(['rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap', 'bg-coral/15 text-coral-700' => $key->scope === \App\Models\ApiKey::MC, 'bg-brand-100 text-brand-700' => $key->scope !== \App\Models\ApiKey::MC])>{{ $key->scope === \App\Models\ApiKey::MC ? 'MC / salaries' : 'Retention daily' }}</span>
                            </td>
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
                        <tr><td colspan="6" class="px-4 py-6 text-center text-muted">No keys yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="mb-4 rounded-xl bg-white p-4 text-sm shadow-sm">
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

    @php($per = $mcCost->govBenefitsPerPerson())
    <section class="rounded-xl bg-white p-4 text-sm shadow-sm">
        <h2 class="text-base font-semibold">MC / salaries <span class="ml-1 rounded-full bg-coral/15 px-2 py-0.5 text-xs font-semibold text-coral-700">Sensitive</span></h2>
        <p class="mt-1 text-muted">CRD's monthly payroll from CRD - MC, for the sales report's Operating Costs. Needs an <strong class="text-ink">MC / salaries</strong> key, sent as <code class="rounded bg-canvas px-1 py-0.5 text-xs text-ink">Authorization: Bearer …</code>. One month per call, 60 calls a minute, HTTPS only, amounts in pesos, Philippine time.</p>
        <pre class="mt-3 overflow-x-auto rounded-lg bg-canvas px-3 py-2 font-mono text-xs text-ink">GET {{ $mcEndpoints['summary'] }}?month=2026-11
GET {{ $mcEndpoints['roster'] }}?month=2026-11</pre>

        <h3 class="mt-5 font-semibold">Summary (no names)</h3>
        <dl class="mt-2 grid gap-x-6 gap-y-2 sm:grid-cols-[12rem_1fr]">
            <dt class="font-mono text-xs font-semibold">headcount</dt><dd class="text-muted">People listed in CRD - MC. Vacant positions aren't counted.</dd>
            <dt class="font-mono text-xs font-semibold">salaries</dt><dd class="text-muted">Their monthly salaries, plus CRD's share of the Sales Manager, VP of Internal Affairs and CEO salaries.</dd>
            <dt class="font-mono text-xs font-semibold">thirteenthMonth · sil</dt><dd class="text-muted">salaries ÷ 12 · salaries × 12 ÷ {{ config('monthly_cost.sil_days') }}, the same formulas as your report.</dd>
            <dt class="font-mono text-xs font-semibold">govBenefits · govBreakdown</dt><dd class="text-muted">A flat amount per person (PHIC + HDMF + SSS, now ₱{{ number_format($per, 2) }}) × headcount, and each of the three × headcount.</dd>
            <dt class="font-mono text-xs font-semibold">updatedAt</dt><dd class="text-muted">When the month's figures last changed.</dd>
        </dl>

        <h3 class="mt-5 font-semibold">Roster (with names, for admins only)</h3>
        <p class="mt-1 text-muted">One row per person: position, name, group (<code class="text-xs">crd team</code>), monthlySalary, allocationPct (100), status (active), startDate and endDate (null: CRD - MC doesn't keep dates). Then one row per shared salary CRD carries: group <code class="text-xs">leadership</code>, the base salary and CRD's share as allocationPct, name null. Nothing else is returned: no bank, government ID, address or contact details.</p>

        <h3 class="mt-5 font-semibold">CRD's answers to the rules</h3>
        <dl class="mt-2 grid gap-x-6 gap-y-2 sm:grid-cols-[12rem_1fr]">
            <dt class="font-semibold">Joining or leaving mid-month</dt><dd class="text-muted">Full monthly salary for whoever is listed in CRD - MC that month. Nothing is prorated; someone removed stops counting from the next save.</dd>
            <dt class="font-semibold">Shared and executive salaries</dt><dd class="text-muted">CRD carries only its share: Sales Manager {{ number_format($mcCost->setting('sales_manager_share'), 2) }}%, VP {{ number_format($mcCost->setting('vp_share'), 2) }}%, CEO {{ number_format($mcCost->setting('ceo_share'), 2) }}% (allocationPct). The rest belongs to the other departments.</dd>
            <dt class="font-semibold">Government benefits</dt><dd class="text-muted">Flat per person, not computed per salary: PHIC ₱{{ number_format($mcCost->setting('phic'), 2) }}, HDMF ₱{{ number_format($mcCost->setting('hdmf'), 2) }}, SSS ₱{{ number_format($mcCost->setting('sss'), 2) }}. Every listed person counts; shared executives don't.</dd>
            <dt class="font-semibold">Frozen history</dt><dd class="text-muted">The current month follows every change in CRD - MC (updatedAt moves). Once a month ends it never changes.</dd>
            <dt class="font-semibold">How far back</dt><dd class="text-muted">Every month since the API was turned on. Earlier months return 404.</dd>
            <dt class="font-semibold">Errors</dt><dd class="text-muted">401 unknown, revoked or missing key · 403 key not made for MC / salaries · 404 month not started or never saved · 422 month not YYYY-MM · 429 over 60 calls a minute.</dd>
            <dt class="font-semibold">Rotating a key</dt><dd class="text-muted">Make a new MC key here, send it, then revoke the old one. Revoking takes effect at once.</dd>
        </dl>
    </section>
</x-layouts.settings>
