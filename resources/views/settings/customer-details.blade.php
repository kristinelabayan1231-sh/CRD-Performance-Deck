<x-layouts.settings title="Customer Database">
    @if ($errors->any())
        <div role="alert" class="mb-4 rounded-lg border border-coral/60 bg-coral/10 px-4 py-3 text-sm text-coral-700">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('settings.customer-details.update') }}">
        @csrf
        @method('PUT')

        <section class="rounded-xl bg-white p-4 shadow-sm">
            <h2 class="text-base font-semibold">Customers' names and numbers</h2>
            <p class="text-sm text-muted">In the Customer Database (Customers, Churn, High AOV CVR &amp; VIP and the customer pop-up). While hidden, VIPs show as VIP 1, VIP 2…, High AOV customers as High AOV 1, High AOV 2… (numbered by total spent, highest first), everyone else as a masked name, and numbers are masked. Super Admins and CRA Supervisors can still see them with the eye button, just for themselves.</p>

            <fieldset class="mt-5">
                <legend class="mb-1.5 text-sm font-semibold">For every role</legend>
                <div class="inline-flex h-10 items-center rounded-lg border border-line p-0.5 text-sm font-semibold">
                    @foreach (['0' => 'Hidden', '1' => 'Shown'] as $value => $label)
                        <label class="h-full cursor-pointer">
                            <input type="radio" name="shown_to_all" value="{{ $value }}" class="peer sr-only" onchange="this.form.submit()" @checked($shownToAll === ((string) $value === '1'))>
                            <span class="grid h-full place-items-center rounded-md px-4 text-muted peer-checked:bg-brand-600 peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-brand-200">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <dl class="mt-5 grid max-w-xl gap-2 rounded-lg bg-canvas/60 p-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-semibold tracking-wide text-muted uppercase">Shown</dt>
                    <dd>Kristine Labayan · 09171234567</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold tracking-wide text-muted uppercase">Hidden</dt>
                    <dd class="tabular-nums">Kr**** L*** · 09*******67</dd>
                </div>
            </dl>

            @if ($lastChange?->editor)
                <p class="mt-4 text-xs text-muted">Last changed by {{ $lastChange->editor->displayName() }} on {{ $lastChange->updated_at->timezone(config('segmentation.timezone'))->format('M j, Y g:i A') }}.</p>
            @endif
        </section>
    </form>
</x-layouts.settings>
