@props(['name'])

@php
    // Line icons for the app shell: the rail's modules, the flyouts and the row of a module's pages.
    $paths = [
        'overview' => 'M4 11 12 4l8 7v9h-5v-6H9v6H4Z',
        'segmentation' => 'M12 3a9 9 0 1 0 9 9h-9ZM15 3.5A9 9 0 0 1 20.5 9H15Z',
        'exclusive' => 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8M12 12h.01',
        'orders' => 'M21 8 12 3 3 8v8l9 5 9-5V8ZM3 8l9 5 9-5M12 13v8',
        'income' => 'M4 20V10M10 20V4M16 20v-7M2 20h20',
        'customers' => 'M16 19v-1a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v1M9 10a3 3 0 1 0 0-6 3 3 0 0 0 0 6M22 19v-1a4 4 0 0 0-3-3.9M16 4.1a3 3 0 0 1 0 5.8',
        'settings' => 'M4 7h10M18 7h2M4 17h2M10 17h10M14 5v4M8 15v4',
        'user' => 'M12 4a4 4 0 1 0 0 8 4 4 0 0 0 0-8M4.5 20c1-4 4-5.5 7.5-5.5s6.5 1.5 7.5 5.5',
        'home' => 'M4 11 12 4l8 7v9h-5v-6H9v6H4Z',
        'report' => 'M6 3h9l4 4v14H6zM14 3v5h5M9 17v-4M12 17v-6M15 17v-2',
        'board' => 'M3 4h18v12H3zM12 16v5M8 21h8M8 12V9M12 12V7M16 12v-2',
        'list' => 'M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01',
        'gauge' => 'M4 18a9 9 0 1 1 16 0M12 14l4-5',
        'funnel' => 'M3 5h18l-7 8v6l-4 2v-8z',
        'mic' => 'M12 3a3 3 0 0 0-3 3v5a3 3 0 0 0 6 0V6a3 3 0 0 0-3-3M5 11a7 7 0 0 0 14 0M12 18v3',
        'send' => 'M21 3 10 14M21 3l-7 18-4-7-7-4z',
        'calendar' => 'M4 6h16v14H4zM4 10h16M8 3v4M16 3v4M9 15l2 2 4-4',
        'star' => 'M12 3l2.7 5.6 6.1.8-4.5 4.2 1.1 6.1L12 16.8l-5.4 2.9 1.1-6.1-4.5-4.2 6.1-.8z',
        'grid' => 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z',
        'trend' => 'M3 17l6-6 4 4 8-8M15 7h6v6',
        'bolt' => 'M13 3 5 14h6l-1 7 8-11h-6z',
        'box' => 'M21 8 12 3 3 8v8l9 5 9-5V8ZM3 8l9 5 9-5M12 13v8',
        'page' => 'M4 4h16v16H4zM4 9h16M9 9v11',
        'target' => 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18M12 7a5 5 0 1 0 0 10 5 5 0 0 0 0-10M12 12h.01',
        'receipt' => 'M6 3h12v18l-3-2-3 2-3-2-3 2zM9 8h6M9 12h6',
        'wallet' => 'M3 7h16v13H3zM3 7l12-4v4M15 13h4',
        'peso' => 'M8 20V4h5a4.5 4.5 0 0 1 0 9H8M5 7.5h14M5 10.5h14',
        'bars' => 'M4 20V10M10 20V4M16 20v-7M2 20h20',
        'users' => 'M16 19v-1a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v1M9 10a3 3 0 1 0 0-6 3 3 0 0 0 0 6M22 19v-1a4 4 0 0 0-3-3.9M16 4.1a3 3 0 0 1 0 5.8',
        'coins' => 'M9 4a5 3 0 1 0 0 6 5 3 0 0 0 0-6M4 7v5c0 1.7 2 3 5 3M10 14a5 3 0 1 0 10 0 5 3 0 0 0-10 0M10 14v4c0 1.7 2 3 5 3s5-1.3 5-3v-4',
        'key' => 'M8 9a4 4 0 1 0 0 8 4 4 0 0 0 0-8M11 11l9-7M17 6l3 3M14.5 8.5l2 2',
        'gear' => 'M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M5.6 18.4l2.1-2.1M16.3 7.7l2.1-2.1',
        'search' => 'M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14M20 20l-4-4',
    ];
@endphp

<svg {{ $attributes->merge(['class' => 'size-5']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $paths[$name] ?? '' }}"/></svg>
