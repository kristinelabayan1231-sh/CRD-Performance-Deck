<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · CRD Performance Deck</title>
    <x-favicons />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
{{-- Sign in: black with glowing gold waves (they drift, light runs along their edges), the CRD wordmark, and the
     glass card. Styles: the "login" section of resources/css/app.css. --}}
<body class="login-page min-h-screen font-sans antialiased">
    @foreach (['top-left', 'bottom-right'] as $corner)
        <svg class="login-wave login-wave-{{ $corner }}" viewBox="0 0 900 620" aria-hidden="true">
            <defs>
                <linearGradient id="wave-{{ $corner }}-a" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#ffc21a" stop-opacity=".95"/><stop offset=".45" stop-color="#b37a00" stop-opacity=".55"/><stop offset="1" stop-color="#111111" stop-opacity="0"/></linearGradient>
                <linearGradient id="wave-{{ $corner }}-b" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#ffd95c" stop-opacity=".55"/><stop offset="1" stop-color="#ffc21a" stop-opacity="0"/></linearGradient>
                <filter id="wave-{{ $corner }}-glow" x="-20%" y="-20%" width="140%" height="140%"><feGaussianBlur stdDeviation="6"/></filter>
            </defs>
            <g class="login-drift">
                <path d="M0,0 H620 C520,70 400,110 280,122 C160,134 70,190 0,300 Z" fill="url(#wave-{{ $corner }}-a)"/>
                <path d="M0,0 H430 C360,40 260,70 180,80 C100,90 40,130 0,190 Z" fill="url(#wave-{{ $corner }}-b)"/>
                <path d="M0,420 C40,250 150,150 300,128 C450,106 560,60 660,-10" fill="none" stroke="#ffc21a" stroke-width="10" opacity=".35" filter="url(#wave-{{ $corner }}-glow)"/>
                <path d="M0,420 C40,250 150,150 300,128 C450,106 560,60 660,-10" fill="none" stroke="#ffe08a" stroke-width="1.6" opacity=".9"/>
                <path class="login-sheen" d="M0,420 C40,250 150,150 300,128 C450,106 560,60 660,-10" pathLength="100" fill="none" stroke="#fff6d6" stroke-width="3" stroke-linecap="round" stroke-dasharray="10 90"/>
                <path d="M0,560 C70,330 210,220 380,190 C540,162 650,90 760,-10" fill="none" stroke="#ffc21a" stroke-width="1" opacity=".45"/>
                <path class="login-sheen login-sheen-slow" d="M0,560 C70,330 210,220 380,190 C540,162 650,90 760,-10" pathLength="100" fill="none" stroke="#ffe08a" stroke-width="2" stroke-linecap="round" stroke-dasharray="8 92"/>
            </g>
        </svg>
    @endforeach

    <main class="login-main">
        @php($letters = '<path d="M98,14 H50 A36,36 0 0 0 50,86 H70"/><path d="M86,14 V92.5"/><path d="M86,30 H110 A15.5,15.5 0 0 1 110,61 H86"/><path d="M144,40 V86 H150 A36,36 0 0 0 150,14 H122"/>')
        <svg class="login-logo" viewBox="0 0 200 100" role="img" aria-label="CRD logo">
            <defs>
                <linearGradient id="crd-gold" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ffd95c"/><stop offset=".55" stop-color="#ffc21a"/><stop offset="1" stop-color="#e0a800"/></linearGradient>
                <linearGradient id="crd-shine" x1="-60" y1="0" x2="0" y2="0" gradientUnits="userSpaceOnUse">
                    <animate attributeName="x1" values="-60;200;200" keyTimes="0;.45;1" dur="5s" repeatCount="indefinite"/>
                    <animate attributeName="x2" values="0;260;260" keyTimes="0;.45;1" dur="5s" repeatCount="indefinite"/>
                    <stop offset="0" stop-color="#ffffff" stop-opacity="0"/><stop offset=".5" stop-color="#ffffff" stop-opacity=".85"/><stop offset="1" stop-color="#ffffff" stop-opacity="0"/>
                </linearGradient>
            </defs>
            <g fill="none" stroke="url(#crd-gold)" stroke-width="13" stroke-linejoin="round">{!! $letters !!}</g>
            <path d="M98,61 H112.5 L140,92.5 H125.5 Z" fill="url(#crd-gold)"/>
            <g class="login-shine" fill="none" stroke="url(#crd-shine)" stroke-width="13" stroke-linejoin="round">{!! $letters !!}<path d="M98,61 H112.5 L140,92.5 H125.5 Z" fill="url(#crd-shine)" stroke="none"/></g>
        </svg>

        <p class="login-title"><span>Customer</span> <b>Retention</b></p>
        <p class="login-department"><i aria-hidden="true"></i><span>Department</span><i aria-hidden="true"></i></p>
        <p class="login-tagline">Retain. Engage. Grow.</p>

        <section class="login-card" aria-labelledby="login-heading">
            <h1 id="login-heading">Welcome <b>Back!</b></h1>
            <p class="login-sub">Sign in to access the Customer Retention Portal</p>

            @error('google')
                <div role="alert" class="login-error">{{ $message }}</div>
            @enderror

            <form method="GET" action="{{ route('auth.google.redirect') }}">
                <button type="submit" class="login-button">
                    <span class="login-google">
                        <svg viewBox="0 0 48 48" aria-hidden="true">
                            <path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.6 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.2-.1-2.4-.4-3.5z"/>
                            <path fill="#FF3D00" d="m6.3 14.7 6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/>
                            <path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/>
                            <path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.2-.1-2.4-.4-3.5z"/>
                        </svg>
                    </span>
                    Sign in with Google
                    <svg class="login-arrow" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                </button>
                <label class="login-remember">
                    <input type="checkbox" name="remember" value="1">
                    Remember me on this device
                </label>
            </form>

            <p class="login-internal">
                <i aria-hidden="true"></i>
                <span>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l7 3v5c0 4.5-3 8.3-7 10-4-1.7-7-5.5-7-10V6l7-3z"/><path d="M8.8 12.2l2.2 2.2 4.2-4.4"/></svg>
                    Internal use only
                </span>
                <i aria-hidden="true"></i>
            </p>
            <p class="login-note">Access is by invitation only. Ask a super admin to add your Google account.</p>
        </section>

        <p class="login-links"><a href="{{ route('privacy') }}">Privacy Policy</a><a href="{{ route('terms') }}">Terms of Service</a></p>
    </main>
</body>
</html>
