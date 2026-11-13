<x-layouts.app title="Preparing">
    <div role="status" class="mx-auto mt-10 max-w-xl rounded-2xl bg-white p-8 text-center shadow-sm ring-1 ring-line">
        <span aria-hidden="true" class="mx-auto mb-4 block size-10 animate-spin rounded-full border-4 border-brand-200 border-t-brand-600"></span>
        <h1 class="text-lg font-semibold text-ink">Preparing this view…</h1>
        <p class="mt-2 text-sm text-muted">It's being worked out in the background so the page doesn't time out. This page reloads by itself every {{ $retryAfter }} seconds until it's ready, usually within a minute or two.</p>
        <a href="{{ request()->fullUrl() }}" class="mt-5 inline-flex h-9 items-center rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">Reload now</a>
    </div>
    <script>setTimeout(() => window.location.reload(), {{ $retryAfter * 1000 }});</script>
</x-layouts.app>
