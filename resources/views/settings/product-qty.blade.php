<x-layouts.settings title="Product Qty">
    <section class="overflow-hidden rounded-xl bg-white shadow-sm">
        <header class="px-4 pt-4 pb-3">
            <h2 class="text-base font-semibold">Product qty in order history</h2>
            <p class="text-sm text-muted">Whether each user sees how many of each product a customer ordered in the Customer Database order history: <span class="font-semibold text-ink">1 × CanPro Guyabano Oil</span> when on, <span class="font-semibold text-ink">CanPro Guyabano Oil</span> when off. Off for everyone unless turned on here.</p>
        </header>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[560px] text-left text-sm">
                <thead class="bg-canvas/60 text-xs tracking-wide text-muted uppercase">
                    <tr>
                        <th class="px-4 py-3 font-semibold">User</th>
                        <th class="px-4 py-3 font-semibold">Role</th>
                        <th class="px-4 py-3 font-semibold">Product qty</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($users as $user)
                        <tr @class(['text-muted' => ! $user->is_active])>
                            <td class="px-4 py-3">
                                <span class="font-medium">{{ $user->displayName() }}</span>
                                <span class="block text-xs text-muted">{{ $user->email }}{{ $user->is_active ? '' : ' · disabled' }}</span>
                            </td>
                            <td class="px-4 py-3">{{ $user->role?->name ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <form method="POST" action="{{ route('settings.product-qty.update', $user) }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="show_product_qty" value="{{ $user->show_product_qty ? 0 : 1 }}">
                                    <button type="submit" role="switch" aria-checked="{{ $user->show_product_qty ? 'true' : 'false' }}"
                                            aria-label="Show product qty for {{ $user->displayName() }}"
                                            class="inline-flex items-center gap-2 rounded-full text-sm font-semibold focus:ring-2 focus:ring-brand-200 focus:outline-none">
                                        <span @class(['relative inline-flex h-6 w-11 items-center rounded-full transition', 'bg-brand-600' => $user->show_product_qty, 'bg-line' => ! $user->show_product_qty])>
                                            <span @class(['inline-block size-5 rounded-full bg-white shadow transition', 'translate-x-5.5' => $user->show_product_qty, 'translate-x-0.5' => ! $user->show_product_qty])></span>
                                        </span>
                                        {{ $user->show_product_qty ? 'On' : 'Off' }}
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
