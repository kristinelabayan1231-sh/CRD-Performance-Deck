<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · CRD Performance Deck</title>
    <x-favicons />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-white font-sans text-ink antialiased">
    <div class="grid min-h-screen lg:grid-cols-2">
        {{-- Left: sign-in --}}
        <div class="flex flex-col px-6 py-8 sm:px-10">
            <a href="{{ route('login') }}" class="flex items-center gap-3">
                <x-crd-logo class="size-10" />
                <span class="text-xl font-semibold tracking-tight">CRD Performance Deck</span>
            </a>

            <div class="flex flex-1 items-center justify-center py-12">
                <div class="w-full max-w-sm">
                    <h1 class="text-4xl font-bold tracking-tight">Welcome back</h1>
                    <p class="mt-3 text-muted">Sign in with your Google account to continue.</p>

                    @error('google')
                        <div role="alert" class="mt-6 rounded-lg border border-coral/60 bg-coral/10 px-4 py-3 text-sm text-coral-700">
                            {{ $message }}
                        </div>
                    @enderror

                    <form method="GET" action="{{ route('auth.google.redirect') }}" class="mt-8 space-y-6">
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="remember" value="1"
                                   class="size-4 rounded border-gray-300 accent-brand-500">
                            Remember me on this device
                        </label>

                        <button type="submit"
                                class="flex w-full items-center justify-center gap-3 rounded-lg bg-brand-600 px-4 py-3 font-semibold text-white shadow-sm transition hover:bg-brand-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500">
                            <span class="grid size-6 place-items-center rounded-full bg-white">
                                <svg class="size-4" viewBox="0 0 48 48" aria-hidden="true">
                                    <path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.6 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.2-.1-2.4-.4-3.5z"/>
                                    <path fill="#FF3D00" d="m6.3 14.7 6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/>
                                    <path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/>
                                    <path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.2-.1-2.4-.4-3.5z"/>
                                </svg>
                            </span>
                            Sign in with Google
                        </button>
                    </form>

                    <p class="mt-8 text-center text-sm text-muted">
                        Access is by invitation only. Ask a super admin to add your Google account.
                    </p>
                </div>
            </div>
        </div>

        {{-- Right: brand panel --}}
        <div class="relative hidden overflow-hidden bg-gradient-to-br from-brand-500 via-violet to-sky lg:flex lg:items-center lg:justify-center">
            <div aria-hidden="true" class="absolute -top-24 -right-24 size-96 rounded-full bg-white/10"></div>
            <div aria-hidden="true" class="absolute -bottom-32 -left-20 size-[28rem] rounded-full bg-white/10"></div>
            <div aria-hidden="true" class="absolute top-1/4 left-16 size-16 rounded-full border-2 border-white/30"></div>
            <div aria-hidden="true" class="absolute right-24 bottom-1/4 size-6 rounded-full bg-coral/80"></div>
            <div aria-hidden="true" class="absolute top-20 left-1/3 size-4 rounded-full bg-teal"></div>

            <div class="relative flex flex-col items-center text-center text-white">
                <x-crd-logo variant="light" class="size-56 drop-shadow-xl" />
                <p class="mt-8 text-3xl font-bold tracking-tight">CRD Performance Deck</p>
                <p class="mt-2 text-white/85">Track page and sales performance in one place.</p>
            </div>
        </div>
    </div>
</body>
</html>
