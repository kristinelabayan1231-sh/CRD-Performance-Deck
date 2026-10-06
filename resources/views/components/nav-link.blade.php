@props(['href', 'active' => false, 'icon' => null])

<a href="{{ $href }}" @if ($active) aria-current="page" @endif
   @class([
       'flex items-center justify-between rounded-lg px-3 py-2.5 font-medium transition',
       'bg-brand-50 text-brand-600' => $active,
       'text-ink hover:bg-brand-50 hover:text-brand-600' => ! $active,
   ])>
    <span>{{ $slot }}</span>
    @if ($icon === 'home')
        <svg class="size-5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2 12h3v8h5v-6h4v6h5v-8h3z"/></svg>
    @elseif ($icon === 'users')
        <svg class="size-5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M16 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm-8 0a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm0 2c-2.7 0-8 1.3-8 4v2h16v-2c0-2.7-5.3-4-8-4Zm8 0c-.3 0-.7 0-1.1.1 1.3.9 2.1 2.2 2.1 3.9v2h7v-2c0-2.7-5.3-4-8-4Z"/></svg>
    @elseif ($icon === 'chart')
        <svg class="size-5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3h2v16h16v2H3V3Zm4 10h3v4H7v-4Zm5-5h3v9h-3V8Zm5-3h3v12h-3V5Z"/></svg>
    @elseif ($icon === 'trend')
        <svg class="size-5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 17.6 9.2 11.4l4 4L19.6 9H17V7h6v6h-2v-2.6l-7.8 7.8-4-4L4.4 19z"/></svg>
    @elseif ($icon === 'funnel')
        <svg class="size-5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4h18l-7 8.5V19l-4 2v-8.5L3 4Z"/></svg>
    @elseif ($icon === 'settings')
        <svg class="size-5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M19.4 13a7.5 7.5 0 0 0 0-2l2.1-1.6-2-3.5-2.5 1a7.6 7.6 0 0 0-1.7-1L15 3.3h-4l-.4 2.6a7.6 7.6 0 0 0-1.7 1l-2.5-1-2 3.5L6.5 11a7.5 7.5 0 0 0 0 2l-2.1 1.6 2 3.5 2.5-1c.5.4 1.1.7 1.7 1l.4 2.6h4l.4-2.6c.6-.3 1.2-.6 1.7-1l2.5 1 2-3.5L19.4 13ZM13 15.5a3.5 3.5 0 1 1 0-7 3.5 3.5 0 0 1 0 7Z" transform="translate(-1 0)"/></svg>
    @endif
</a>
