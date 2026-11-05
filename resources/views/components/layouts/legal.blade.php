@props(['title'])

{{-- Public pages linked from the Google sign-in screen (no sign-in needed). --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · CRD Performance Deck</title>
    <x-favicons />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-canvas font-sans text-ink antialiased">
    <main class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
        <a href="{{ route('login') }}" class="mb-8 flex items-center gap-3">
            <x-crd-logo class="size-10" />
            <span class="text-xl font-semibold tracking-tight">CRD Performance Deck</span>
        </a>

        <article class="space-y-5 rounded-2xl border border-line bg-white p-6 text-sm leading-relaxed shadow-sm sm:p-8 [&_h2]:pt-2 [&_h2]:text-base [&_h2]:font-semibold [&_ul]:list-disc [&_ul]:space-y-1 [&_ul]:pl-5">
            <header>
                <h1 class="text-2xl font-bold">{{ $title }}</h1>
                <p class="mt-1 text-muted">Last updated October 23, 2026</p>
            </header>
            {{ $slot }}
        </article>

        <nav class="mt-6 flex gap-4 text-sm text-muted" aria-label="Legal">
            <a href="{{ route('privacy') }}" class="hover:text-brand-600 hover:underline">Privacy Policy</a>
            <a href="{{ route('terms') }}" class="hover:text-brand-600 hover:underline">Terms of Service</a>
            <a href="{{ route('login') }}" class="hover:text-brand-600 hover:underline">Sign in</a>
        </nav>
    </main>
</body>
</html>
