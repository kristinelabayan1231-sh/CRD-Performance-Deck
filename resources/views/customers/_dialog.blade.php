{{-- Customer details, loaded when a row is clicked; $title names the pop-up (CLTV by default). Full view fills the screen for presenting. --}}
@php($privacy = app(\App\Support\CustomerPrivacy::class))
@php($hidden = $privacy->isHidden())
@php($headerButton = 'flex h-8 items-center gap-1.5 rounded-lg px-2.5 text-xs font-semibold transition')
<dialog id="customer-dialog" aria-labelledby="customer-dialog-title"
        class="group/dialog m-auto max-h-[90dvh] w-[min(56rem,calc(100%-2rem))] overflow-hidden rounded-xl p-0 shadow-2xl backdrop:bg-ink/40 data-[full]:h-dvh data-[full]:max-h-none data-[full]:w-screen data-[full]:max-w-none data-[full]:rounded-none">
    <div class="flex max-h-[90dvh] flex-col group-data-[full]/dialog:h-full group-data-[full]/dialog:max-h-none">
        <div class="flex shrink-0 items-center justify-between gap-4 bg-gradient-to-r from-brand-600 to-brand-700 px-5 py-3 text-white group-data-[full]/dialog:px-8 group-data-[full]/dialog:py-4">
            <h2 id="customer-dialog-title" class="text-base font-bold group-data-[full]/dialog:text-xl">{{ $title ?? 'Customer Life Time Value (CLTV)' }}</h2>
            <div class="flex items-center gap-2">
                {{-- Same as the eye button beside the tabs: hides names and numbers without closing the pop-up --}}
                @if ($privacy->canReveal())
                <button type="button" data-customer-privacy="{{ route('customers.privacy') }}" aria-pressed="{{ $hidden ? 'true' : 'false' }}"
                        title="Hide or show customers' names and numbers"
                        class="group/eye {{ $headerButton }} bg-white/15 text-white hover:bg-white/25 aria-pressed:bg-white aria-pressed:text-brand-700">
                    <svg class="hidden size-4 group-aria-pressed/eye:block" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18M10.6 5.1A9.8 9.8 0 0 1 12 5c5 0 8.6 4.4 9.6 6.4a1.3 1.3 0 0 1 0 1.2 15 15 0 0 1-3 3.9M6.6 6.6A15 15 0 0 0 2.4 11.4a1.3 1.3 0 0 0 0 1.2C3.4 14.6 7 19 12 19a9.5 9.5 0 0 0 5.4-1.6M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
                    <svg class="size-4 group-aria-pressed/eye:hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.4 11.4C3.4 9.4 7 5 12 5s8.6 4.4 9.6 6.4a1.3 1.3 0 0 1 0 1.2C20.6 14.6 17 19 12 19s-8.6-4.4-9.6-6.4a1.3 1.3 0 0 1 0-1.2Z"/><circle cx="12" cy="12" r="3"/></svg>
                    <span class="hidden group-aria-pressed/eye:inline">Details hidden</span>
                    <span class="group-aria-pressed/eye:hidden">Hide details</span>
                </button>
                @else
                    <span class="flex h-8 items-center gap-1.5 rounded-lg px-2.5 text-xs font-semibold bg-white/15 text-white"
                          title="Hidden for every role. A Super Admin or CRA Supervisor can show them in Settings → Customer Database.">
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path stroke-linecap="round" d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
                        Details hidden
                    </span>
                @endif
                <button type="button" data-customer-full class="{{ $headerButton }} bg-white/15 text-white hover:bg-white/25">
                    <svg class="size-4 group-data-[full]/dialog:hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg>
                    <svg class="hidden size-4 group-data-[full]/dialog:block" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 4v5H4M15 4v5h5M9 20v-5H4M15 20v-5h5"/></svg>
                    <span class="group-data-[full]/dialog:hidden">Full view</span>
                    <span class="hidden group-data-[full]/dialog:inline">Exit full view</span>
                </button>
                <button type="button" data-customer-close aria-label="Close" class="rounded-lg p-1.5 text-white/80 hover:bg-white/15 hover:text-white">
                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>
        </div>
        <div data-customer-body class="float-in min-h-0 overflow-y-auto p-5 group-data-[full]/dialog:flex-1 group-data-[full]/dialog:px-10 group-data-[full]/dialog:py-7"></div>
    </div>
</dialog>
