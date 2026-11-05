@php
    $input = 'h-10 w-full min-w-0 rounded-lg border border-line bg-white px-3 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none';
    $since = \Carbon\CarbonImmutable::parse(config('customers.delivered_from'))->format('M j, Y');
    $logisticsFrom = \Carbon\CarbonImmutable::parse(config('customers.logistics_from'));
    $chip = 'rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-ink shadow-sm ring-1 ring-line';
@endphp

<x-layouts.settings title="Pancake Accounts">
    @if ($errors->any())
        <div role="alert" class="mb-4 rounded-lg border border-coral/60 bg-coral/10 px-4 py-3 text-sm text-coral-700">{{ $errors->first() }}</div>
    @endif

    <div class="grid items-start gap-4 xl:grid-cols-2">
        {{-- List 1: CRD team accounts (editable) --}}
        <x-panel title="CRD team accounts" icon="users" title-id="crd-accounts-title" body-class="space-y-4 p-4">
            <x-slot:badges>
                <span class="rounded-full bg-white px-2 py-0.5 text-xs font-bold text-brand-700 tabular-nums shadow-sm">{{ count($crdAccounts) }}</span>
            </x-slot:badges>

            <div class="rounded-xl bg-white p-3 text-sm shadow-sm">
                <p class="mb-2 text-xs font-semibold tracking-wide text-muted uppercase">Where it's used</p>
                <div class="mb-2 flex flex-wrap gap-1.5"><span class="{{ $chip }}">Customer Database</span></div>
                <ul class="list-disc space-y-1 pl-5 text-xs text-muted">
                    <li>Orders sold by these accounts count as <span class="font-semibold text-ink">handled by a CRA</span>: the CRD Leads, Retained and Repeat Customers tiles and labels, and "By a CRA" in the customer pop-up.</li>
                    <li>The check of each CRD customer's orders <span class="font-semibold text-ink">before {{ $since }}</span> (Retained vs Repeat).</li>
                    <li>Pancake deliveries from {{ $since }} to {{ $logisticsFrom->subDay()->format('M j') }} (before the logistics report) are labeled <span class="font-semibold text-ink">CRD</span> when one of these accounts sold them. Saving relabels them right away.</li>
                    <li>Not used by the Dashboard, Conversion Breakdown, Segmentation Productivity or order issues: those use the CRA accounts.</li>
                </ul>
            </div>

            <form method="POST" action="{{ route('settings.pancake-accounts.update') }}" data-account-list class="rounded-xl bg-white p-3 shadow-sm">
                @csrf
                @method('PUT')
                <p class="mb-2 text-xs text-muted">Current and past CRD seller accounts, as named in Pancake. Capitals and extra spaces don't matter.</p>
                <ul data-account-rows class="space-y-2">
                    @foreach (old('accounts', $crdAccounts) as $name)
                        <li data-account-item class="flex items-center gap-2">
                            <input type="text" name="accounts[]" value="{{ $name }}" maxlength="100" aria-label="Account name" class="{{ $input }}">
                            <button type="button" data-account-remove aria-label="Remove {{ $name }}" title="Remove"
                                    class="grid size-10 shrink-0 place-items-center rounded-lg text-muted hover:bg-coral/10 hover:text-coral-700">
                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
                            </button>
                        </li>
                    @endforeach
                </ul>
                <template data-account-row>
                    <li data-account-item class="flex items-center gap-2">
                        <input type="text" name="accounts[]" maxlength="100" placeholder="e.g. CRD Juan Dela Cruz" aria-label="Account name" class="{{ $input }}">
                        <button type="button" data-account-remove aria-label="Remove" title="Remove"
                                class="grid size-10 shrink-0 place-items-center rounded-lg text-muted hover:bg-coral/10 hover:text-coral-700">
                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
                        </button>
                    </li>
                </template>
                <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                    <button type="button" data-account-add class="h-10 rounded-lg border border-dashed border-line px-3 text-sm font-semibold text-brand-600 hover:border-brand-400">+ Add account</button>
                    <button type="submit" class="h-10 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white shadow-sm hover:bg-brand-700">Save accounts</button>
                </div>
                @if ($lastChange?->editor)
                    <p class="mt-3 text-xs text-muted">Last changed by {{ $lastChange->editor->displayName() }} on {{ $lastChange->updated_at->timezone(config('segmentation.timezone'))->format('M j, Y g:i A') }}.</p>
                @endif
            </form>

            {{-- Earlier orders are looked up in Pancake once per customer; saving checks the past month's customers again. --}}
            <div class="rounded-xl bg-white p-3 text-sm shadow-sm">
                <p class="font-semibold">Orders before {{ $since }}</p>
                <p class="text-xs text-muted">
                    Checked <span class="font-semibold tabular-nums text-ink">{{ number_format($history['checked']) }} of {{ number_format($history['total']) }}</span> CRD customers.
                    Saving the accounts checks again the customers delivered in the month before (in the background, about 1,000 every 15 minutes); everyone else keeps their last result.
                    @if ($recheckFrom)
                        Last change: {{ $recheckFrom->timezone(config('segmentation.timezone'))->format('M j, Y g:i A') }}.
                    @endif
                </p>
            </div>
        </x-panel>

        {{-- List 2: CRA accounts (from User Access, read-only here) --}}
        <x-panel title="CRA accounts" accent="teal" icon="users" title-id="cra-accounts-title" body-class="space-y-4 p-4">
            <x-slot:badges>
                <span class="rounded-full bg-white px-2 py-0.5 text-xs font-bold text-teal-700 tabular-nums shadow-sm">{{ $craUsers->where('is_active', true)->count() }}</span>
            </x-slot:badges>
            @can('user_access.manage')
                <x-slot:actions>
                    <a href="{{ route('user-access.index') }}" class="font-semibold text-brand-600 hover:underline">Edit in User Access &rarr;</a>
                </x-slot:actions>
            @endcan

            <div class="rounded-xl bg-white p-3 text-sm shadow-sm">
                <p class="mb-2 text-xs font-semibold tracking-wide text-muted uppercase">Where it's used</p>
                <div class="mb-2 flex flex-wrap gap-1.5">
                    @foreach (['Dashboard', 'Confirmed Orders', 'Conversion Breakdown', 'Segmentation Productivity', 'Order issues', 'Customer Database'] as $module)
                        <span class="{{ $chip }}">{{ $module }}</span>
                    @endforeach
                </div>
                <ul class="list-disc space-y-1 pl-5 text-xs text-muted">
                    <li>Each CRA's sales, confirmed orders, engagements and conversion are the orders and chats of <span class="font-semibold text-ink">their own account and their other accounts</span>. Orders sold by any other account aren't credited to anyone.</li>
                    <li>The Customer Database counts these accounts as handled by a CRA too, with the CRD team accounts.</li>
                    <li>Set each CRA's own account in User Access; add their older or second accounts here. Switching a CRA's account in User Access keeps the previous one here.</li>
                </ul>
            </div>

            @if ($craUsers->isEmpty())
                <p class="rounded-xl bg-white px-4 py-6 text-center text-sm text-muted shadow-sm">No CRA has a Pancake account yet. Set it in User Access.</p>
            @else
                <form method="POST" action="{{ route('settings.pancake-accounts.aliases') }}" class="overflow-hidden rounded-xl bg-white shadow-sm">
                    @csrf
                    @method('PUT')
                    <ul class="divide-y divide-line">
                        @foreach ($craUsers as $cra)
                            <li data-account-list class="space-y-2 p-3">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-sm"><span class="font-semibold">{{ $cra->displayName() }}</span> <span class="text-muted">· {{ $cra->pancake_name }}</span></p>
                                    <span @class(['rounded-full px-2 py-0.5 text-xs font-semibold', 'bg-teal/15 text-teal-700' => $cra->is_active, 'bg-canvas text-muted' => ! $cra->is_active])>{{ $cra->is_active ? 'Active' : 'Disabled' }}</span>
                                </div>
                                <ul data-account-rows class="space-y-2">
                                    @foreach (old("aliases.{$cra->id}", $aliases[$cra->id] ?? []) as $name)
                                        <li data-account-item class="flex items-center gap-2">
                                            <input type="text" name="aliases[{{ $cra->id }}][]" value="{{ $name }}" maxlength="100" aria-label="{{ $cra->displayName() }}'s other account" class="{{ $input }}">
                                            <button type="button" data-account-remove aria-label="Remove {{ $name }}" title="Remove"
                                                    class="grid size-10 shrink-0 place-items-center rounded-lg text-muted hover:bg-coral/10 hover:text-coral-700">
                                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                                <template data-account-row>
                                    <li data-account-item class="flex items-center gap-2">
                                        <input type="text" name="aliases[{{ $cra->id }}][]" maxlength="100" placeholder="Other account, as named in Pancake" aria-label="{{ $cra->displayName() }}'s other account" class="{{ $input }}">
                                        <button type="button" data-account-remove aria-label="Remove" title="Remove"
                                                class="grid size-10 shrink-0 place-items-center rounded-lg text-muted hover:bg-coral/10 hover:text-coral-700">
                                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
                                        </button>
                                    </li>
                                </template>
                                <button type="button" data-account-add class="h-9 rounded-lg border border-dashed border-line px-3 text-xs font-semibold text-brand-600 hover:border-brand-400">+ Add other account</button>
                            </li>
                        @endforeach
                    </ul>
                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-line p-3">
                        <p class="text-xs text-muted">
                            Capitals and extra spaces don't matter.
                            @if ($aliasesChange?->editor)
                                Last changed by {{ $aliasesChange->editor->displayName() }} on {{ $aliasesChange->updated_at->timezone(config('segmentation.timezone'))->format('M j, Y g:i A') }}.
                            @endif
                        </p>
                        <button type="submit" class="h-10 rounded-lg bg-teal-700 px-4 text-sm font-semibold text-white shadow-sm hover:bg-[#0b6b5d]">Save CRA accounts</button>
                    </div>
                </form>
            @endif
        </x-panel>
    </div>
</x-layouts.settings>
