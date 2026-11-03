@php
    // Colours are inline: the board is dark in both themes, so the generated dark theme must not restyle it.
    $money = fn (float $value) => ($value < 0 ? '−' : '').'₱'.number_format(abs($value), 2);
    $net = fn (float $value) => $value < 0 ? 'color: #ff9a9d' : 'color: #3fd1b9';
    $glass = 'background: rgb(45 42 62 / .55); -webkit-backdrop-filter: blur(18px) saturate(1.4); backdrop-filter: blur(18px) saturate(1.4); border: 1px solid rgb(255 255 255 / .12); border-radius: 16px';
    $head = 'margin: 0; padding: 10px 18px; font-size: 14px; font-weight: 600; border-bottom: 1px solid rgb(255 255 255 / .1); border-radius: 16px 16px 0 0';
    $label = 'margin: 0; font-size: 13px; font-weight: 600; color: #bdb6cf';
@endphp

{{-- CRD Board: the Daily I.S. figures (every CRA) on a dark glass card. --}}
<div data-board-file="CRD Board - {{ $today['day']->toDateString() }}.png" class="relative isolate flex flex-col gap-4 overflow-hidden p-5 text-center tabular-nums sm:p-7"
     style="background-color: #211f2d; color: #f1eef8; border-radius: 22px;
            background-image: radial-gradient(circle at 15% 12%, rgb(124 77 255 / .5), transparent 45%), radial-gradient(circle at 95% 25%, rgb(27 207 180 / .22), transparent 40%), radial-gradient(circle at 70% 92%, rgb(160 90 255 / .35), transparent 45%)">
    <div>
        <h2 style="margin: 0; font-size: 28px; font-weight: 700; line-height: 1.15">CRD Board</h2>
        <p style="margin: 2px 0 0; font-size: 14px; color: #bdb6cf">Customer Retention Department · {{ $today['day']->format('D, M j, Y') }}</p>
    </div>

    <section aria-label="Today, running" style="{{ $glass }}; background: rgb(58 49 87 / .55); border-color: rgb(195 155 255 / .35); border-radius: 18px; padding: 18px 20px 22px; box-shadow: 0 0 40px rgb(124 77 255 / .18)">
        <p style="margin: 0; font-size: 14px; font-weight: 600; color: #c39bff">
            <span aria-hidden="true" style="display: inline-block; width: 9px; height: 9px; border-radius: 50%; background: #3fd1b9; margin-right: 8px"></span>Running sales today · as of {{ $asOf->format('g:i A') }}
        </p>
        <div class="mt-3 grid grid-cols-2">
            <div style="border-right: 1px solid rgb(255 255 255 / .14)">
                <p style="{{ $label }}">Gross Sales</p>
                <p style="margin: 0; font-size: clamp(22px, 5.6vw, 50px); font-weight: 700; line-height: 1.1; letter-spacing: -1px; white-space: nowrap">{{ $money($today['gross']) }}</p>
            </div>
            <div>
                <p style="{{ $label }}">Net Income</p>
                <p style="margin: 0; font-size: clamp(22px, 5.6vw, 50px); font-weight: 700; line-height: 1.1; letter-spacing: -1px; white-space: nowrap; {{ $net($today['net']) }}">{{ $money($today['net']) }}</p>
            </div>
        </div>
    </section>

    <section aria-label="Last two days" style="{{ $glass }}">
        <p class="grid grid-cols-3" style="{{ $head }}; background: linear-gradient(90deg, rgb(70 58 108 / .65), rgb(58 49 87 / .3))">
            <span>Last two days</span><span style="color: #bdb6cf">Gross Sales</span><span style="color: #bdb6cf">Net Income</span>
        </p>
        @foreach ($days as $row)
            <div class="grid grid-cols-3 items-center" style="padding: 12px 18px; {{ $loop->last ? '' : 'border-bottom: 1px solid rgb(255 255 255 / .08)' }}">
                <p style="margin: 0; font-size: 16px; font-weight: 600">{{ $row['day']->format('D, M j') }}</p>
                <p style="margin: 0; font-size: clamp(15px, 2.8vw, 24px); font-weight: 700; white-space: nowrap">{{ $money($row['gross']) }}</p>
                <p style="margin: 0; font-size: clamp(15px, 2.8vw, 24px); font-weight: 700; white-space: nowrap; {{ $net($row['net']) }}">{{ $money($row['net']) }}</p>
            </div>
        @endforeach
    </section>

    <div class="grid gap-4 sm:grid-cols-2">
        <section aria-label="Top seller" class="flex flex-col" style="{{ $glass }}">
            <p style="{{ $head }}; background: linear-gradient(90deg, rgb(58 54 82 / .65), rgb(52 48 74 / .3))">Top seller · {{ $topDay->format('M j') }}</p>
            @if ($topSeller)
                <div class="flex grow flex-col items-center" style="padding: 14px 18px">
                    <span style="border-radius: 50%; box-shadow: 0 0 0 2px #2d2a3e, 0 0 0 4px #c39bff">
                        <x-avatar :user="$topSeller['user']" class="size-15 text-lg" />
                    </span>
                    <p style="margin: 8px 0 0; font-size: 17px; font-weight: 700">{{ $topSeller['user']->displayName() }}</p>
                    <div class="mt-1.5 grid w-full grid-cols-2 gap-2.5">
                        <div><p style="{{ $label }}">Gross Sales</p><p style="margin: 0; font-size: 20px; font-weight: 700; white-space: nowrap">{{ $money($topSeller['gross']) }}</p></div>
                        <div><p style="{{ $label }}">Net Income</p><p style="margin: 0; font-size: 20px; font-weight: 700; white-space: nowrap; {{ $net($topSeller['net']) }}">{{ $money($topSeller['net']) }}</p></div>
                    </div>
                </div>
            @else
                <p class="grow" style="margin: 0; padding: 28px 18px; color: #bdb6cf">No sales on {{ $topDay->format('M j') }}.</p>
            @endif
        </section>
        {{-- With a photo: it fills the section, darkened towards the bottom so the figures stay readable. --}}
        @php($photo = $topProduct['image'] ?? null)
        <section aria-label="Top product" class="flex flex-col overflow-hidden"
                 style="{{ $glass }}{{ $photo ? "; border-color: rgb(255 255 255 / .2); background: linear-gradient(180deg, rgb(33 31 45 / .1) 0%, rgb(33 31 45 / .45) 40%, rgb(33 31 45 / .92) 78%), url('{$photo}') center 30% / cover no-repeat" : '' }}">
            <p style="{{ $head }}; {{ $photo ? 'background: rgb(33 31 45 / .55); -webkit-backdrop-filter: blur(8px); backdrop-filter: blur(8px)' : 'background: linear-gradient(90deg, rgb(36 66 63 / .65), rgb(38 54 52 / .3))' }}">Top product · {{ $topDay->format('M j') }}</p>
            @if ($topProduct)
                <div class="flex grow flex-col items-center {{ $photo ? 'justify-end' : 'justify-center' }}" style="padding: 14px 18px; {{ $photo ? 'min-height: 150px; text-shadow: 0 1px 8px rgb(0 0 0 / .6)' : '' }}">
                    <p style="margin: 0; font-size: 19px; font-weight: 700">{{ $topProduct['name'] }}</p>
                    <div class="mt-2.5 grid w-full grid-cols-2 gap-2.5">
                        <div><p style="{{ $label }}">Gross Sales</p><p style="margin: 0; font-size: 20px; font-weight: 700; white-space: nowrap">{{ $money($topProduct['gross']) }}</p></div>
                        <div><p style="{{ $label }}">Net Income</p><p style="margin: 0; font-size: 20px; font-weight: 700; white-space: nowrap; {{ $net($topProduct['net']) }}">{{ $money($topProduct['net']) }}</p></div>
                    </div>
                </div>
            @else
                <p class="grow" style="margin: 0; padding: 28px 18px; color: #bdb6cf">No sales on {{ $topDay->format('M j') }}.</p>
            @endif
        </section>
    </div>
</div>
