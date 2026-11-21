@props(['title' => 'CRD Performance Deck'])

{{-- A page with no sidebar or top bar, for content shown inside a pop-up. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} · CRD Performance Deck</title>
    {{-- Same Light / Dark as the main window (set by the top bar's switch, remembered on this browser) --}}
    <script>try { document.documentElement.dataset.theme = localStorage.getItem('theme') === 'dark' ? 'dark' : 'light'; } catch (e) { document.documentElement.dataset.theme = 'light'; }</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @if (is_file(public_path('build/assets/theme-dark.css')))
        <link rel="stylesheet" href="{{ asset('build/assets/theme-dark.css') }}?v={{ filemtime(public_path('build/assets/theme-dark.css')) }}">
    @endif
</head>
<body class="bg-white font-sans text-ink antialiased">
    {{ $slot }}
</body>
</html>
