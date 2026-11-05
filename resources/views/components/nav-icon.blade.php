@props(['name'])

{{-- The sidebar's icons by name (nav-link and the group headers). --}}
@if ($name === 'home')
    <svg {{ $attributes->merge(['class' => 'size-5']) }} fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2 12h3v8h5v-6h4v6h5v-8h3z"/></svg>
@elseif ($name === 'users')
    <svg {{ $attributes->merge(['class' => 'size-5']) }} fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M16 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm-8 0a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm0 2c-2.7 0-8 1.3-8 4v2h16v-2c0-2.7-5.3-4-8-4Zm8 0c-.3 0-.7 0-1.1.1 1.3.9 2.1 2.2 2.1 3.9v2h7v-2c0-2.7-5.3-4-8-4Z"/></svg>
@elseif ($name === 'chart')
    <svg {{ $attributes->merge(['class' => 'size-5']) }} fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3h2v16h16v2H3V3Zm4 10h3v4H7v-4Zm5-5h3v9h-3V8Zm5-3h3v12h-3V5Z"/></svg>
@elseif ($name === 'trend')
    <svg {{ $attributes->merge(['class' => 'size-5']) }} fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 17.6 9.2 11.4l4 4L19.6 9H17V7h6v6h-2v-2.6l-7.8 7.8-4-4L4.4 19z"/></svg>
@elseif ($name === 'funnel')
    <svg {{ $attributes->merge(['class' => 'size-5']) }} fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4h18l-7 8.5V19l-4 2v-8.5L3 4Z"/></svg>
@elseif ($name === 'report')
    <svg {{ $attributes->merge(['class' => 'size-5']) }} fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 2h9l5 5v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Zm8 1.5V8h4.5L14 3.5ZM7 12v2h10v-2H7Zm0 4v2h10v-2H7Zm0-8v2h5V8H7Z"/></svg>
@elseif ($name === 'pc')
    <svg {{ $attributes->merge(['class' => 'size-5']) }} fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 3h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2h-6v2h3v2H7v-2h3v-2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Zm0 2v10h16V5H4Z"/></svg>
@elseif ($name === 'cvr')
    <svg {{ $attributes->merge(['class' => 'size-5']) }} fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 3h11l4 4v14H5V3Zm2 2v14h11V8h-3V5H7Zm2 8h2v4H9v-4Zm3-3h2v7h-2v-7Zm3 1.5h2V17h-2v-5.5Z"/></svg>
@elseif ($name === 'statement')
    <svg {{ $attributes->merge(['class' => 'size-5']) }} fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 2h14a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1Zm1 2v16h12V4H6Zm2 3h5v2H8V7Zm0 4h8v2H8v-2Zm0 4h8v2H8v-2Zm7-8h1v2h-1V7Z"/></svg>
@elseif ($name === 'target')
    <svg {{ $attributes->merge(['class' => 'size-5']) }} fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8 8 8 0 0 1-8 8Zm0-14a6 6 0 1 0 6 6 6 6 0 0 0-6-6Zm0 10a4 4 0 1 1 4-4 4 4 0 0 1-4 4Zm0-6a2 2 0 1 0 2 2 2 2 0 0 0-2-2Z"/></svg>
@elseif ($name === 'products')
    <svg {{ $attributes->merge(['class' => 'size-5']) }} fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2 3 6.5v11L12 22l9-4.5v-11L12 2Zm0 2.2 6.3 3.1L12 10.5 5.7 7.3 12 4.2ZM5 9l6 3v7.3l-6-3V9Zm8 10.3V12l6-3v7.3l-6 3Z"/></svg>
@elseif ($name === 'orders')
    <svg {{ $attributes->merge(['class' => 'size-5']) }} fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 4V2h10v2h3a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h3Zm0 2H5v14h14V6h-2v2H7V6Zm2-2v2h6V4H9Zm-2 7h2v2H7v-2Zm4 0h6v2h-6v-2Zm-4 4h2v2H7v-2Zm4 0h6v2h-6v-2Z"/></svg>
@elseif ($name === 'database')
    <svg {{ $attributes->merge(['class' => 'size-5']) }} fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2C7.6 2 4 3.3 4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5c0-1.7-3.6-3-8-3Zm6 17c-.4.4-2.6 1-6 1s-5.6-.6-6-1v-3c1.5.8 3.7 1.2 6 1.2s4.5-.4 6-1.2v3Zm0-5c-.4.4-2.6 1.2-6 1.2S6.4 14.4 6 14v-3c1.5.8 3.7 1.2 6 1.2s4.5-.4 6-1.2v3Zm-6-3.8C8.7 10.2 6.4 9.4 6 9V7.2C7.5 7.7 9.7 8 12 8s4.5-.3 6-.8V9c-.4.4-2.7 1.2-6 1.2ZM12 6C8.7 6 6.5 5.4 6 5c.5-.4 2.7-1 6-1s5.5.6 6 1c-.5.4-2.7 1-6 1Z"/></svg>
@elseif ($name === 'wallet')
    <svg {{ $attributes->merge(['class' => 'size-5']) }} fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5a3 3 0 0 0-3 3v9a3 3 0 0 0 3 3h16a2 2 0 0 0 2-2v-2h-5a3 3 0 0 1 0-6h5V8a2 2 0 0 0-2-2H5a1 1 0 0 1 0-2h13V3H5a2 2 0 0 0-1 2Zm13 6a1 1 0 0 0 0 2h5v-2h-5Z"/></svg>
@elseif ($name === 'settings')
    <svg {{ $attributes->merge(['class' => 'size-5']) }} fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M19.4 13a7.5 7.5 0 0 0 0-2l2.1-1.6-2-3.5-2.5 1a7.6 7.6 0 0 0-1.7-1L15 3.3h-4l-.4 2.6a7.6 7.6 0 0 0-1.7 1l-2.5-1-2 3.5L6.5 11a7.5 7.5 0 0 0 0 2l-2.1 1.6 2 3.5 2.5-1c.5.4 1.1.7 1.7 1l.4 2.6h4l.4-2.6c.6-.3 1.2-.6 1.7-1l2.5 1 2-3.5L19.4 13ZM13 15.5a3.5 3.5 0 1 1 0-7 3.5 3.5 0 0 1 0 7Z" transform="translate(-1 0)"/></svg>
@endif
