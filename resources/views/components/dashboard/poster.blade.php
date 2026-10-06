@php
    // Today and the two days before it, written like the wall poster ("OCTOBER 4, 2026"). Everything is editable.
    $today = \App\Models\Lead::today();
    $days = collect([2, 1, 0])->map(fn (int $back) => strtoupper($today->subDays($back)->format('F j, Y')));
    $field = 'poster-field w-full min-w-0 rounded-sm border-0 border-b-2 border-dashed border-transparent bg-transparent p-0 text-inherit placeholder:text-ink/25 hover:border-ink/15 focus:border-brand-500 focus:ring-0 focus:outline-none';
@endphp

<link rel="stylesheet" href="https://fonts.bunny.net/css?family=patrick-hand:400&display=swap">

{{-- Poster button (beside the page title) --}}
<button type="button" data-poster-open aria-haspopup="dialog"
        class="group inline-flex items-center gap-1.5 rounded-lg bg-gradient-to-br from-brand-500 to-brand-700 px-2.5 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500"
        title="Open the CRD poster board">
    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M3 4h18M5 4v11a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V4M12 16v4m-3 0h6M8 9h3m-3 3h5"/>
    </svg>
    CRD Board
</button>

{{-- The board: typed values only, nothing is saved (a reload clears it). --}}
<dialog data-poster aria-label="CRD poster board"
        class="m-auto w-[min(64rem,calc(100vw-2rem))] max-w-none overflow-visible bg-transparent p-0 backdrop:bg-ink/70 backdrop:backdrop-blur-sm">
    <div data-poster-stage class="relative flex flex-col items-center gap-0 bg-transparent px-2 pt-2 pb-4 [&:fullscreen]:justify-center [&:fullscreen]:bg-[#f3f1ec]">
        {{-- Toolbar --}}
        <div class="mb-3 flex w-full items-center justify-between gap-2 text-xs font-semibold">
            <p class="rounded-full bg-white/90 px-3 py-1 text-ink shadow-sm">Type on the board. Nothing is saved; a reload clears it.</p>
            <div class="flex items-center gap-2">
                <button type="button" data-poster-clear class="rounded-full bg-white/90 px-3 py-1 text-ink shadow-sm hover:bg-white">Clear</button>
                <button type="button" data-poster-fullscreen class="rounded-full bg-white/90 px-3 py-1 text-ink shadow-sm hover:bg-white">Full screen</button>
                <button type="button" data-poster-close class="grid size-7 place-items-center rounded-full bg-white/90 text-ink shadow-sm hover:bg-white" aria-label="Close board">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>
        </div>

        {{-- Hanging rod --}}
        <div aria-hidden="true" class="relative z-10 h-3 w-[102%] rounded-full bg-gradient-to-b from-[#d6d3cd] via-[#9c9890] to-[#6f6b64] shadow"></div>

        {{-- Paper with blue tape grid --}}
        <form data-poster-board onsubmit="return false"
              class="poster-tape relative -mt-1 grid w-full grid-cols-1 gap-3 p-3 text-ink shadow-2xl sm:grid-cols-2 sm:gap-4 sm:p-4"
              style="font-family: 'Patrick Hand', 'Comic Sans MS', 'Chalkboard SE', cursive;">
            <div class="bg-white px-5 py-3 sm:col-span-2">
                <p class="text-center text-2xl tracking-wide sm:text-left sm:text-4xl">CUSTOMER RETENTION DEPARTMENT [CRD]</p>
            </div>

            @foreach ($days as $i => $day)
                <section class="flex flex-col gap-1 bg-white px-5 py-4 text-2xl sm:text-3xl" aria-label="Day {{ $i + 1 }}">
                    <label class="sr-only" for="poster-day-{{ $i }}">Date</label>
                    <input id="poster-day-{{ $i }}" type="text" value="{{ $day }}" data-default="{{ $day }}" class="{{ $field }} text-3xl sm:text-4xl">
                    <label class="flex items-baseline gap-2">
                        <span class="shrink-0">Gross Sales:</span>
                        <input type="text" inputmode="decimal" placeholder="0.00" data-money data-default="" class="{{ $field }}">
                    </label>
                    <label class="flex items-baseline gap-2">
                        <span class="shrink-0">Net Income:</span>
                        <input type="text" inputmode="decimal" placeholder="0.00" data-money data-net data-default="" class="{{ $field }}">
                    </label>
                </section>
            @endforeach

            <section class="flex flex-col items-center gap-0.5 bg-white px-5 py-4 text-2xl sm:text-3xl" aria-label="Top seller">
                <p class="flex items-center gap-3 text-2xl tracking-wide sm:text-3xl">
                    <span aria-hidden="true" class="text-brand-600">⟩⟩</span> TOP SELLER <span aria-hidden="true" class="text-brand-600">⟨⟨</span>
                </p>
                <label class="sr-only" for="poster-top-name">Top seller name</label>
                <input id="poster-top-name" type="text" placeholder="NAME" data-default="" class="{{ $field }} text-center text-4xl uppercase sm:text-5xl">
                <label class="flex w-full items-baseline gap-2">
                    <span class="shrink-0">Gross Sales:</span>
                    <input type="text" inputmode="decimal" placeholder="0.00" data-money data-default="" class="{{ $field }}">
                </label>
                <label class="flex w-full items-baseline gap-2">
                    <span class="shrink-0">Net Income:</span>
                    <input type="text" inputmode="decimal" placeholder="0.00" data-money data-net data-default="" class="{{ $field }}">
                </label>
            </section>
        </form>

        {{-- CRD mascot: a headset-wearing customer-care blob, waving from under the board --}}
        <div class="relative -mt-6 flex items-end gap-2">
            <svg data-poster-mascot class="h-36 w-auto drop-shadow-lg sm:h-44" viewBox="0 0 200 180" role="img" aria-label="CRD mascot waving">
                <ellipse cx="100" cy="172" rx="58" ry="6" fill="#000" opacity=".12"/>
                {{-- Feet --}}
                <ellipse cx="78" cy="164" rx="16" ry="9" fill="#7429d6"/>
                <ellipse cx="122" cy="164" rx="16" ry="9" fill="#7429d6"/>
                {{-- Body --}}
                <path d="M100 30c40 0 62 34 62 74 0 36-24 60-62 60s-62-24-62-60c0-40 22-74 62-74Z" fill="#a05aff"/>
                <path d="M100 30c40 0 62 34 62 74 0 10-2 19-5 27-8-40-30-80-57-80S51 91 43 131c-3-8-5-17-5-27 0-40 22-74 62-74Z" fill="#b47fff" opacity=".55"/>
                {{-- Belly badge --}}
                <ellipse cx="100" cy="128" rx="30" ry="22" fill="#f6efff"/>
                <text x="100" y="136" text-anchor="middle" font-family="'Patrick Hand', 'Comic Sans MS', cursive" font-size="24" fill="#7429d6">CRD</text>
                {{-- Headset --}}
                <path d="M52 86c0-30 21-48 48-48s48 18 48 48" fill="none" stroke="#343a40" stroke-width="6" stroke-linecap="round"/>
                <rect x="40" y="78" width="16" height="26" rx="7" fill="#343a40"/>
                <rect x="144" y="78" width="16" height="26" rx="7" fill="#343a40"/>
                <path d="M48 104c0 14 14 22 34 22" fill="none" stroke="#343a40" stroke-width="4" stroke-linecap="round"/>
                <circle cx="84" cy="126" r="5" fill="#343a40"/>
                {{-- Face --}}
                <ellipse cx="82" cy="88" rx="9" ry="11" fill="#fff"/>
                <ellipse cx="118" cy="88" rx="9" ry="11" fill="#fff"/>
                <circle cx="84" cy="90" r="5.5" fill="#343a40"/>
                <circle cx="120" cy="90" r="5.5" fill="#343a40"/>
                <circle cx="86" cy="87" r="2" fill="#fff"/>
                <circle cx="122" cy="87" r="2" fill="#fff"/>
                <ellipse cx="70" cy="104" rx="7" ry="4.5" fill="#fe9496" opacity=".85"/>
                <ellipse cx="130" cy="104" rx="7" ry="4.5" fill="#fe9496" opacity=".85"/>
                <path d="M92 104c4 6 12 6 16 0" fill="none" stroke="#343a40" stroke-width="3.5" stroke-linecap="round"/>
                {{-- Waving arm and resting arm --}}
                <g class="poster-wave" style="transform-origin: 156px 112px">
                    <path d="M154 114c12-8 20-20 22-34" fill="none" stroke="#a05aff" stroke-width="14" stroke-linecap="round"/>
                    <circle cx="177" cy="76" r="10" fill="#b47fff"/>
                </g>
                <path d="M46 116c-10 6-14 16-12 26" fill="none" stroke="#a05aff" stroke-width="14" stroke-linecap="round"/>
                {{-- Sparkles --}}
                <path d="M30 40l3 8 8 3-8 3-3 8-3-8-8-3 8-3Z" fill="#ffd166"/>
                <path d="M176 30l2 5 5 2-5 2-2 5-2-5-5-2 5-2Z" fill="#1bcfb4"/>
            </svg>
            {{-- Speech bubble (editable too) --}}
            <label class="relative mb-20 rounded-2xl bg-white px-4 py-2 text-xl text-ink shadow-lg sm:mb-24 sm:text-2xl" style="font-family: 'Patrick Hand', 'Comic Sans MS', cursive;">
                <span class="sr-only">Mascot message</span>
                <input type="text" value="Great job, team!" data-default="Great job, team!" class="{{ $field }} w-44 sm:w-56">
                <span aria-hidden="true" class="absolute -bottom-2 left-4 size-4 rotate-45 bg-white"></span>
            </label>
        </div>
    </div>
</dialog>
