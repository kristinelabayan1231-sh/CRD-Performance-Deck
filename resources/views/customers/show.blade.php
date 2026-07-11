@extends('layouts.app')

@section('title', ($customer['name'] ?? 'Customer') . ' — ' . config('app.name'))

@section('content')
    <x-page-header :title="$customer['name'] ?? 'Customer'" subtitle="Customer details, purchase history, and lifetime value.">
        <x-slot:actions>
            <a href="{{ route('customers.index') }}" class="text-xs font-medium text-slate-400 hover:text-slate-600">&larr; Back to Customers</a>
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="rounded-xl bg-white p-5 shadow-sm lg:col-span-1">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Personal Details</p>
            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-slate-400">Phone</dt><dd class="text-right text-slate-700">{{ implode(', ', $customer['phone_numbers'] ?? []) ?: '—' }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-slate-400">Gender</dt><dd class="text-right text-slate-700">{{ ucfirst($customer['gender'] ?? '') ?: '—' }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-slate-400">Date of Birth</dt><dd class="text-right text-slate-700">{{ $customer['date_of_birth'] ?? '—' }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-slate-400">Email</dt><dd class="text-right text-slate-700">{{ implode(', ', $customer['emails'] ?? []) ?: '—' }}</dd></div>
                @if (! empty($customer['shop_customer_addresses']))
                    <div class="flex justify-between gap-3"><dt class="shrink-0 text-slate-400">Address</dt><dd class="text-right text-slate-700">{{ $customer['shop_customer_addresses'][0]['full_address'] ?? '—' }}</dd></div>
                @endif
                @if (! empty($customer['tags']))
                    <div class="flex justify-between gap-3"><dt class="shrink-0 text-slate-400">Tags</dt><dd class="text-right text-slate-700">{{ implode(', ', $customer['tags']) }}</dd></div>
                @endif
                @if (! empty($customer['conversation_link']))
                    <div class="pt-2">
                        <a href="{{ $customer['conversation_link'] }}" target="_blank" rel="noopener" class="text-xs font-medium text-teal-600 hover:text-teal-800">Open conversation in Pancake &rarr;</a>
                    </div>
                @endif
            </dl>
        </div>

        <div class="rounded-xl bg-white p-5 shadow-sm lg:col-span-2">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Lifetime Value</p>
            <div class="mt-3 grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div>
                    <p class="text-xs text-slate-400">LTV / CLTV</p>
                    <p class="mt-1 text-xl font-bold text-slate-900">₱{{ number_format($customer['purchased_amount'] ?? 0) }}</p>
                </div>
                <div>
                    <p class="text-xs text-slate-400">Total Orders</p>
                    <p class="mt-1 text-xl font-bold text-slate-900">{{ $customer['order_count'] ?? 0 }}</p>
                </div>
                <div>
                    <p class="text-xs text-slate-400">Succeeded Orders</p>
                    <p class="mt-1 text-xl font-bold text-slate-900">{{ $customer['succeed_order_count'] ?? 0 }}</p>
                </div>
                <div>
                    <p class="text-xs text-slate-400">Returned Orders</p>
                    <p class="mt-1 text-xl font-bold text-slate-900">{{ $customer['returned_order_count'] ?? 0 }}</p>
                </div>
            </div>

            <div class="mt-5 border-t border-slate-100 pt-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Top Purchased Product</p>
                @if ($topProduct)
                    <p class="mt-2 text-sm text-slate-700">
                        <span class="font-medium text-slate-900">{{ $topProduct['name'] }}</span>
                        &middot; {{ $topProduct['quantity'] }} unit{{ $topProduct['quantity'] === 1 ? '' : 's' }} purchased
                    </p>
                @else
                    <p class="mt-2 text-sm text-slate-400">No purchase history found for this customer.</p>
                @endif
            </div>
        </div>
    </div>

    <div class="mt-6 overflow-hidden rounded-xl bg-white shadow-sm">
        <div class="border-b border-slate-100 px-5 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500">
            Order History ({{ count($orders) }})
        </div>
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                    <th class="px-5 py-2 font-medium">Date</th>
                    <th class="px-5 py-2 font-medium">Status</th>
                    <th class="px-5 py-2 font-medium">Items</th>
                    <th class="px-5 py-2 font-medium text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($orders as $order)
                    <tr class="border-b border-slate-100 last:border-0">
                        <td class="px-5 py-3 text-slate-500">{{ \Illuminate\Support\Carbon::parse($order['inserted_at'])->format('M j, Y') }}</td>
                        <td class="px-5 py-3">
                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">{{ ucfirst($order['status_name'] ?? '—') }}</span>
                        </td>
                        <td class="px-5 py-3 text-slate-500">
                            {{ collect($order['items'] ?? [])->map(fn ($i) => ($i['variation_info']['name'] ?? '?') . ' x' . ($i['quantity'] ?? 1))->implode(', ') }}
                        </td>
                        <td class="px-5 py-3 text-right font-medium text-slate-900">₱{{ number_format($order['total_price'] ?? 0) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-5 py-6 text-center text-slate-400">No orders found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
