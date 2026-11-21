@php
    // Colours are CSS variables (--pb-*) set below for Light and for Dark (<html data-theme>, the top bar's switch), so the
    // board keeps its own glass look in both and the generated dark theme doesn't restyle it.
    $number = fn (int|float|null $value) => $value === null ? '—' : number_format($value);
    $signed = fn (int|float $value) => ($value > 0 ? '+' : ($value < 0 ? '−' : '')).number_format(abs($value));
    $peso = fn (int|float|null $value) => $value === null ? '—' : '₱'.number_format($value);
    // Short sales label: ₱2.99M from a million, ₱460K below it.
    $millions = fn (int|float $value) => abs($value) >= 1000000 ? '₱'.number_format($value / 1000000, 2).'M' : '₱'.number_format($value / 1000).'K';
    // Short money that stays exact for small amounts: ₱8,450 under ₱10K, ₱352K or ₱1.46M above.
    $pesoShort = fn (int|float $value) => abs($value) >= 10000 ? $millions($value) : $peso($value);
    // Short count: 12K from ten thousand.
    $compact = fn (int|float $value) => abs($value) >= 10000 ? number_format($value / 1000).'K' : number_format($value);
    $percent = fn (?float $value) => $value === null ? '—' : number_format($value * 100, 1).'%';
    // A rate with what it is out of.
    $of = fn (int|float $part, int|float $base) => $base ? number_format($part / $base * 100, 1).'% ('.number_format($part).' of '.number_format($base).')' : '—';
    $text = 'var(--pb-text)';
    $small = 'display: block; font-size: 11px; font-weight: 400; color: var(--pb-muted); white-space: nowrap';
    $blur = '-webkit-backdrop-filter: blur(18px) saturate(1.4); backdrop-filter: blur(18px) saturate(1.4)';
    $glass = 'background: var(--pb-glass); '.$blur.'; border: 1px solid var(--pb-line); border-radius: 16px';
    $hero = 'background: var(--pb-hero); '.$blur.'; border: 1px solid var(--pb-hero-line); border-radius: 16px; box-shadow: var(--pb-hero-glow)';
    $head = 'background: var(--pb-head); border-bottom: 1px solid var(--pb-line); border-radius: 16px 16px 0 0; padding: 12px 16px';
    $cell = 'background: var(--pb-cell); border: 1px solid var(--pb-line); border-radius: 12px';
    $h2 = 'margin: 0; font-size: 16px; font-weight: 700';
    $muted = 'color: var(--pb-muted)';
    $note = 'margin: 0; font-size: 13px; line-height: 1.5; color: var(--pb-muted)';
    $fsd = 'var(--pb-fsd)';
    $fsdTint = 'var(--pb-fsd-tint)';
    $crd = 'var(--pb-crd)';
    $crdTint = 'var(--pb-crd-tint)';
    $added = 'var(--pb-added)';
    $addedTint = 'var(--pb-added-tint)';
    $lost = 'var(--pb-lost)';
    $reactivation = 'var(--pb-react)';
    $accent = 'var(--pb-accent)';
    $broadcast = 'var(--pb-bc)';
    $exclusiveColor = 'var(--pb-excl)';
    $axis = 'border-bottom: 1px solid var(--pb-axis)';
    $rowLine = 'border-bottom: 1px solid var(--pb-row)';
    $swatch = 'width: 10px; height: 10px; border-radius: 3px';
    $palette = ['var(--pb-crd)', 'var(--pb-fsd)', 'var(--pb-added)', 'var(--pb-bc)', 'var(--pb-excl)', 'var(--pb-lost)'];

    // The change of the last month against the one before: counts in %, rates in points.
    $chip = function (int|float|null $current, int|float|null $before, bool $rate = false, bool $lowerIsBetter = false, bool $neutral = false): array {
        $base = 'display: inline-block; align-self: flex-start; max-width: 100%; padding: 2px 8px; border-radius: 999px; font-size: 12px; font-weight: 700; line-height: 1.4; white-space: normal; ';
        if ($current === null || $before === null || (! $rate && (float) $before === 0.0)) {
            return ['text' => 'no earlier month', 'style' => $base.'background: var(--pb-chip); color: var(--pb-muted)'];
        }
        $change = $rate ? ($current - $before) * 100 : ($current - $before) / $before * 100;
        $up = $change > .05;
        $down = $change < -.05;
        $good = ! $neutral && ($lowerIsBetter ? $down : $up);
        $bad = ! $neutral && ($lowerIsBetter ? $up : $down);

        return [
            'text' => ($up ? '▲ +' : ($down ? '▼ −' : '')).number_format(abs($change), 1).($rate ? ' pts' : '%'),
            'style' => $base.($good ? 'background: var(--pb-good-bg); color: var(--pb-good)' : ($bad ? 'background: var(--pb-bad-bg); color: var(--pb-bad)' : 'background: var(--pb-chip); color: var(--pb-soft)')),
        ];
    };

    // A trend line, one point per value (null = no point), the last one bigger; hollow when that month isn't over.
    $sparkline = function (array $values, string $color, bool $openLast = false): string {
        $real = array_values(array_filter($values, fn ($value) => $value !== null));
        if ($real === []) {
            return '';
        }
        $max = max($real);
        $min = min($real);
        $low = $max == $min ? 0 : max(0, $min - ($max - $min) * .6);
        $count = count($values);
        $points = [];
        $dots = '';
        foreach (array_values($values) as $index => $value) {
            if ($value === null) {
                continue;
            }
            $x = round(($index + .5) / $count * 300, 1);
            $y = round(62 - ($value - $low) / (($max - $low) ?: 1) * 52, 1);
            $points[] = [$x, $y];
            $isLast = $index === $count - 1;
            $dots .= '<circle cx="'.$x.'" cy="'.$y.'" r="'.($isLast ? 6 : 4).'" style="fill: '.($isLast && $openLast ? 'var(--pb-solid)' : $color).'; stroke: '.$color.'; stroke-width: 2.5"></circle>';
        }
        $line = implode(' ', array_map(fn (array $point) => $point[0].','.$point[1], $points));
        $area = count($points) > 1 ? '<path d="M'.$points[0][0].',70 L'.str_replace(' ', ' L', $line).' L'.end($points)[0].',70 Z" style="fill: '.$color.'; opacity: .14"></path>' : '';

        return '<svg viewBox="0 0 300 70" aria-hidden="true" style="display: block; width: 100%; height: auto; overflow: visible">'.$area
            .'<polyline points="'.$line.'" style="fill: none; stroke: '.$color.'; stroke-width: 3; stroke-linejoin: round; stroke-linecap: round"></polyline>'.$dots.'</svg>';
    };

    // A donut: $parts = list of [name, value, color, text]; the centre shows $center over $sub.
    $donut = function (array $parts, string $center, string $sub, string $label, int $size = 150): string {
        $total = array_sum(array_column($parts, 1));
        $done = 0;
        $segments = '';
        foreach ($parts as [$name, $value, $color, $shown]) {
            if ($total <= 0 || $value <= 0) {
                continue;
            }
            $share = $value / $total * 100;
            $segments .= '<circle cx="21" cy="21" r="15.9155" style="fill: none; stroke: '.$color.'; stroke-width: 6; stroke-dasharray: '.round(max(0, $share - .4), 2).' '.round(100 - $share + .4, 2).'; stroke-dashoffset: '.round(125 - $done, 2).'"><title>'.e($name.': '.$shown.' ('.number_format($share, 1).'%)').'</title></circle>';
            $done += $share;
        }

        return '<svg viewBox="0 0 42 42" role="img" aria-label="'.e($label).'" style="display: block; width: '.$size.'px; height: '.$size.'px; flex: none">'
            .'<circle cx="21" cy="21" r="15.9155" style="fill: none; stroke: var(--pb-track); stroke-width: 6"></circle>'.$segments
            .'<text x="21" y="21" style="fill: var(--pb-text); font-size: 4.4px; font-weight: 700; text-anchor: middle">'.e($center).'</text>'
            .'<text x="21" y="26" style="fill: var(--pb-muted); font-size: 2.8px; text-anchor: middle">'.e($sub).'</text></svg>';
    };
@endphp

<x-layouts.app title="Presentation Board">
    <style>
        [data-presentation-board] {
            --pb-page: #f3effa;
            --pb-glow: radial-gradient(circle at 12% 3%, rgb(124 77 255 / .14), transparent 30%), radial-gradient(circle at 95% 15%, rgb(27 207 180 / .1), transparent 26%), radial-gradient(circle at 70% 65%, rgb(160 90 255 / .08), transparent 34%);
            --pb-text: #1f1b2e; --pb-muted: #5d566f; --pb-soft: #463f58; --pb-strong: #1f1b2e;
            --pb-glass: rgb(255 255 255 / .78); --pb-solid: #ffffff; --pb-cell: rgb(124 77 255 / .04); --pb-drawer: rgb(124 77 255 / .05);
            --pb-line: rgb(31 27 46 / .1); --pb-line2: rgb(31 27 46 / .14); --pb-row: rgb(31 27 46 / .07);
            --pb-hero: rgb(243 236 255 / .92); --pb-hero-line: rgb(124 77 255 / .3); --pb-hero-glow: 0 4px 24px rgb(124 77 255 / .1);
            --pb-head: linear-gradient(90deg, rgb(124 77 255 / .12), rgb(124 77 255 / .03));
            --pb-pill: rgb(124 77 255 / .12); --pb-pill-text: #4b2a9e; --pb-chip: rgb(31 27 46 / .07);
            --pb-pos-bg: rgb(15 140 120 / .12); --pb-pos-text: #0b6e5f;
            --pb-field: #ffffff; --pb-field-line: rgb(31 27 46 / .18); --pb-btn: #6b3fd4; --pb-btn-text: #ffffff; --pb-on: rgb(124 77 255 / .16);
            --pb-accent: #6b3fd4; --pb-track: rgb(31 27 46 / .07); --pb-grid: rgb(31 27 46 / .08); --pb-axis: rgb(31 27 46 / .25);
            --pb-mark: rgb(124 77 255 / .08); --pb-link-line: rgb(31 27 46 / .35);
            --pb-good-bg: rgb(15 140 120 / .12); --pb-good: #0b6e5f; --pb-bad-bg: rgb(214 60 70 / .1); --pb-bad: #b3263a;
            --pb-crd: #8a3ff0; --pb-crd-tint: rgb(138 63 240 / .3); --pb-fsd: #1f86ad; --pb-fsd-tint: rgb(31 134 173 / .3);
            --pb-added: #1aa58f; --pb-added-tint: rgb(26 165 143 / .32); --pb-lost: #d64550; --pb-react: rgb(31 27 46 / .22);
            --pb-bc: #c86a14; --pb-excl: #c2367a;
            color-scheme: light;
        }
        html[data-theme="dark"] [data-presentation-board] {
            --pb-page: #211f2d;
            --pb-glow: radial-gradient(circle at 12% 3%, rgb(124 77 255 / .5), transparent 28%), radial-gradient(circle at 95% 15%, rgb(27 207 180 / .22), transparent 26%), radial-gradient(circle at 70% 65%, rgb(160 90 255 / .3), transparent 34%);
            --pb-text: #f1eef8; --pb-muted: #bdb6cf; --pb-soft: #d8d2e6; --pb-strong: #f1eef8;
            --pb-glass: rgb(45 42 62 / .55); --pb-solid: #2c2940; --pb-cell: rgb(255 255 255 / .05); --pb-drawer: rgb(33 31 45 / .55);
            --pb-line: rgb(255 255 255 / .12); --pb-line2: rgb(255 255 255 / .14); --pb-row: rgb(255 255 255 / .08);
            --pb-hero: rgb(58 49 87 / .55); --pb-hero-line: rgb(195 155 255 / .35); --pb-hero-glow: 0 0 40px rgb(124 77 255 / .18);
            --pb-head: linear-gradient(90deg, rgb(70 58 108 / .65), rgb(58 49 87 / .3));
            --pb-pill: rgb(195 155 255 / .2); --pb-pill-text: #e4d3ff; --pb-chip: rgb(255 255 255 / .1);
            --pb-pos-bg: rgb(63 209 185 / .18); --pb-pos-text: #b5f2e6;
            --pb-field: #2d2a3e; --pb-field-line: rgb(255 255 255 / .18); --pb-btn: #c39bff; --pb-btn-text: #211f2d; --pb-on: rgb(195 155 255 / .28);
            --pb-accent: #c39bff; --pb-track: rgb(255 255 255 / .1); --pb-grid: rgb(255 255 255 / .1); --pb-axis: rgb(255 255 255 / .3);
            --pb-mark: rgb(255 255 255 / .08); --pb-link-line: rgb(255 255 255 / .45);
            --pb-good-bg: rgb(63 209 185 / .16); --pb-good: #6fe3cf; --pb-bad-bg: rgb(255 154 157 / .16); --pb-bad: #ffb3b5;
            --pb-crd: #a05aff; --pb-crd-tint: rgb(160 90 255 / .38); --pb-fsd: #2a9fc9; --pb-fsd-tint: rgb(42 159 201 / .35);
            --pb-added: #3fd1b9; --pb-added-tint: rgb(63 209 185 / .4); --pb-lost: #ff9a9d; --pb-react: rgb(255 255 255 / .28);
            --pb-bc: #f5a35c; --pb-excl: #ff7eb6;
            color-scheme: dark;
        }
        [data-presentation-board] summary { list-style: none; }
        [data-presentation-board] summary::-webkit-details-marker { display: none; }
        [data-presentation-board] details[open] > summary .pb-chevron { transform: rotate(90deg); }
        /* The board is drawn in px; the rest of the app is at 87.5% (html font-size), so the board matches it. */
        [data-presentation-board] { zoom: .875; }
        [data-presentation-board] .pb-tip-box { display: none; }
        [data-presentation-board] .pb-dot { position: absolute; display: flex; align-items: center; justify-content: center; width: 22px; height: 22px; margin: 0 0 -11px -11px; padding: 0; border: 0; background: transparent; cursor: pointer; }
        [data-presentation-board] .pb-dot-mark { display: block; width: 9px; height: 9px; box-sizing: border-box; border: 2px solid; border-radius: 50%; transition: transform .12s; }
        [data-presentation-board] .pb-dot:hover .pb-dot-mark, [data-presentation-board] .pb-dot:focus-visible .pb-dot-mark { transform: scale(1.45); }
        [data-presentation-board] .pb-dot:focus-visible { outline: 2px solid var(--pb-accent-line, currentColor); outline-offset: 1px; border-radius: 50%; }
        [data-presentation-board] .pb-dot-tip { display: none; position: absolute; bottom: 22px; z-index: 30; min-width: 150px; padding: 8px 10px; border-radius: 10px; background: var(--pb-text); color: var(--pb-solid); box-shadow: 0 8px 24px rgb(0 0 0 / .22); text-align: left; white-space: nowrap; pointer-events: none; }
        [data-presentation-board] .pb-dot:hover .pb-dot-tip, [data-presentation-board] .pb-dot:focus-visible .pb-dot-tip { display: block; }
        [data-presentation-board] [data-cra-card]:hover, [data-presentation-board] [data-cra-card]:focus-within { position: relative; z-index: 5; }
        [data-presentation-board] .pb-tile:hover, [data-presentation-board] .pb-tile:focus-within { z-index: 30; }
        [data-presentation-board] .pb-tip:hover .pb-tip-box, [data-presentation-board] .pb-tip:focus-within .pb-tip-box { display: block; }
        [data-presentation-board] [data-product-sort][aria-pressed="true"] { background: var(--pb-on); color: var(--pb-text); font-weight: 600; }
        [data-presentation-board] [data-product-sort][aria-pressed="false"] { background: transparent; color: var(--pb-muted); font-weight: 400; }
    </style>

    <div data-presentation-board class="flex flex-col gap-4 tabular-nums"
         style="margin: -4px; padding: 20px; border-radius: 22px; color: var(--pb-text); background-color: var(--pb-page); background-image: var(--pb-glow)">

        @if (! $snapshot)
            <h1 style="margin: 0; font-size: 28px; font-weight: 700">Presentation Board</h1>
            <p role="status" style="{{ $glass }}; margin: 0; padding: 20px">The year's figures are being prepared. They appear here after the next scheduled refresh, within {{ \App\Services\PresentationBoard::EVERY_HOURS }} hours.</p>
        @else
            @php
                $linkQuery = ['from' => $from, 'to' => $to];
                $link = 'color: inherit; text-decoration: underline; text-decoration-style: dotted; text-decoration-color: var(--pb-link-line); text-underline-offset: 3px';
                $listUrl = fn (string $metric, ?string $month = null) => route('presentation-board.customers', ['metric' => $metric, 'from' => $month ?? $from, 'to' => $month ?? $to]);
                $dates = fn (?string $month = null) => [
                    'from' => ($month ?? $from).'-01',
                    'to' => \Carbon\CarbonImmutable::parse(($month ?? $to).'-01')->endOfMonth()->min(\Carbon\CarbonImmutable::parse($snapshot['today']))->toDateString(),
                ];
                $churnUrl = fn (string $status, string $list, ?string $month = null) => route('customers.churn', [...$dates($month), 'status' => $status, 'list' => $list]);
                // The cards' rates cover complete months only, so their lists do too.
                $churnFrom = collect($months)->firstWhere('churn_partial', false)['key'] ?? $from;
                $cardUrl = fn (string $status, string $list) => route('customers.churn', ['from' => $churnFrom.'-01', 'to' => $dates()['to'], 'status' => $status, 'list' => $list]);
                $ordersUrl = fn (?string $month = null) => route('conversion.orders', $dates($month));
                $col = fn (string $key) => array_column($months, $key);
                // A snapshot taken before the Segmentation / Broadcast split has no tag figures: say so rather than show ₱0.
                $hasSplit = array_key_exists('sc_gross', reset($snapshot['days']) ?: []);
                $monthCount = count($months);
                $lastMonth = $months[$monthCount - 1];
                $firstMonth = $monthCount > 1 ? $months[0] : null;
                $lastKey = $lastMonth['key'];
                $trackerAt = collect($months)->search(fn (array $month) => $month['source'] === 'tracker');
                $hasPartial = $totals['churn_partial'];
                $star = fn (array $month) => $month['churn_partial'] ? '*' : '';
                // The month still running: counts that build up day by day are compared at its full-month pace.
                $today = \Carbon\CarbonImmutable::parse($snapshot['today']);
                $isRunning = $lastKey === $today->format('Y-m') && ! $today->isLastOfMonth();
                $paceFactor = $isRunning ? $today->daysInMonth / $today->day : 1;
                $paced = fn (?float $value) => $value === null ? null : $value * $paceFactor;
                // The running month shows its days so far (Nov 1–21) wherever it is named.
                $monthName = fn (array $month) => $isRunning && $month['key'] === $lastKey ? $month['label'].' 1–'.$today->day : $month['label'];
                $versus = $firstMonth ? $monthName($lastMonth).' vs '.$firstMonth['label'] : 'one month picked';
                // The last month against the first month picked that has a figure (conversion starts in October, a count
                // may be 0 early on): the chip and the months it names, or null when there is nothing earlier to compare.
                $changeOf = function (array $values, bool $rate = false, bool $flow = false, bool $lowerIsBetter = false, bool $neutral = false) use ($chip, $paced, $months, $monthCount, $monthName, $isRunning) {
                    $values = array_values($values);
                    $last = $values[$monthCount - 1] ?? null;
                    $base = collect($values)->search(fn ($value, int $index) => $index < $monthCount - 1 && $value !== null && (float) $value != 0.0);
                    if ($last === null || $base === false) {
                        return null;
                    }
                    $change = $chip($flow && $isRunning ? $paced($last) : $last, $values[$base], $rate, $lowerIsBetter, $neutral);

                    return [...$change, 'text' => $change['text'].' · '.$monthName($months[$monthCount - 1]).' vs '.$months[$base]['label']];
                };
                $noChange = $monthCount > 1 ? 'No earlier month with a figure' : 'One month picked';
                $monthGrid = 'display: grid; grid-template-columns: repeat('.$monthCount.', minmax(0, 1fr)); gap: 4px';
                // Values under a trend line: every month for up to six, else the first and last, so long ranges stay readable.
                $everyValue = $monthCount <= 6;
                // Values under a trend line, one column per label (the months picked unless $labels is given). Every value for
                // up to six columns, else the first and last, pinned to the edges so long numbers aren't cut off.
                $valueRow = function (array $values, callable $format, ?array $labels = null, bool $narrow = false) use ($months, $monthName) {
                    $labels ??= array_column($months, 'label');
                    $count = count($labels);
                    $every = $count <= 6;
                    $cells = '';
                    foreach (array_values($labels) as $index => $label) {
                        $value = array_values($values)[$index] ?? null;
                        $isEdge = $index === 0 || $index === $count - 1;
                        $align = $every || ! $isEdge ? 'center' : ($index === 0 ? 'flex-start' : 'flex-end');
                        $cells .= '<span style="display: flex; flex-direction: column; align-items: '.$align.'; min-width: 0; font-size: 10px; color: var(--pb-muted); white-space: nowrap; overflow: '.($every || ! $isEdge ? 'hidden' : 'visible').'">'
                            .'<strong style="font-size: 11px; color: var(--pb-soft)">'.(($every || $isEdge) && $value !== null ? e($format($value)) : '&nbsp;').'</strong>'.($narrow && ! $every && ! $isEdge ? '&nbsp;' : e($label)).'</span>';
                    }

                    return '<div style="display: grid; grid-template-columns: repeat('.$count.', minmax(0, 1fr)); gap: 4px; padding-top: 3px; border-top: 1px solid var(--pb-line2)">'.$cells.'</div>';
                };
                $tiles = [
                    ['label' => 'New customers', 'old' => 'was Acquired leads', 'dot' => $added, 'value' => $number($totals['added']), 'url' => $listUrl('added'), 'sub' => '+'.$number($totals['added_waiting']).' waiting',
                        'values' => $col('added'), 'format' => $compact, 'flow' => true,
                        'info' => ['A customer whose first delivery on record (records start June 2025) came from FSD.', 'Counted once, ever, in the month of that first delivery.', 'Not the same as Due to reorder · FSD: that counts people in the month their supply runs out, often a month or two later, and includes older FSD customers.', 'Example: first delivery Oct 5, 45-day supply → New customer in October, Due to reorder · FSD in November.']],
                    ['label' => 'Due to reorder · FSD', 'old' => 'was FSD leads', 'dot' => $fsd, 'value' => $number($totals['fsd_leads']), 'url' => $listUrl('fsd_leads'), 'sub' => '+'.$number($totals['fsd_waiting']).' waiting',
                        'values' => $col('fsd_leads'), 'format' => $compact, 'neutral' => true],
                    ['label' => 'Due to reorder · CRD', 'old' => 'was CRD leads', 'dot' => $crd, 'value' => $number($totals['crd_leads']), 'url' => $listUrl('crd_leads'), 'sub' => '+'.$number($totals['crd_waiting']).' waiting',
                        'values' => $col('crd_leads'), 'format' => $compact, 'neutral' => true],
                    ['label' => 'FSD retained', 'dot' => $fsd, 'value' => $number($totals['retained']), 'url' => $listUrl('retained'), 'sub' => $percent($totals['retained_rate']).' of '.$number($totals['retained_base']),
                        'values' => $col('retained_rate'), 'format' => $percent, 'rate' => true],
                    ['label' => 'CRD repeat', 'dot' => $crd, 'value' => $number($totals['repeat']), 'url' => $listUrl('repeat'), 'sub' => $percent($totals['repeat_rate']).' of '.$number($totals['repeat_base']),
                        'values' => $col('repeat_rate'), 'format' => $percent, 'rate' => true],
                    ['label' => 'Conversion', 'dot' => $accent, 'value' => $percent($totals['conversion_rate']), 'valueNote' => 'overall', 'sub' => 'From October · overall includes broadcast',
                        'values' => $col('conversion_rate'), 'format' => $percent, 'rate' => true,
                        'parts' => [['FSD leads', $fsd, $totals['conversion_leads']['fsd']], ['CRD leads', $crd, $totals['conversion_leads']['crd']]]],
                    ['label' => 'Orders', 'dot' => $accent, 'value' => $number($totals['orders']), 'url' => $ordersUrl(), 'sub' => 'AOV '.$peso($totals['aov']),
                        'values' => $col('orders'), 'format' => $compact, 'flow' => true],
                ];
                $rateCards = [
                    ['label' => 'Conversion', 'color' => $accent, 'value' => $totals['conversion_rate'], 'key' => 'conversion_rate', 'sub' => $totals['conversion_base'] ? $number($totals['conversion_customers']).' of '.$number($totals['conversion_base']).' · FSD leads '.$percent($totals['conversion_leads']['fsd']['rate']).' · CRD leads '.$percent($totals['conversion_leads']['crd']['rate']).' · Segmentation '.$percent($totals['conversion_tabs']['sc']).' · Broadcast '.$percent($totals['conversion_tabs']['bc']) : 'No tracker data in these months'],
                    ['label' => 'Retain (FSD)', 'color' => $fsd, 'value' => $totals['retained_rate'], 'key' => 'retained_rate', 'sub' => $number($totals['retained']).' of '.$number($totals['retained_base']).' FSD customers bought again from a CRA'],
                    ['label' => 'Repeat (CRD)', 'color' => $crd, 'value' => $totals['repeat_rate'], 'key' => 'repeat_rate', 'sub' => $number($totals['repeat']).' of '.$number($totals['repeat_base']).' CRD customers bought again'],
                ];
                $teams = [
                    ['name' => 'FSD leads', 'leads' => 'fsd_leads', 'wait' => 'fsd_waiting', 'bought' => 'retained', 'base' => 'retained_base', 'rate' => 'retained_rate', 'lost' => 'fsd_lost', 'due' => 'fsd_due', 'churn' => 'fsd_churn_rate', 'boughtName' => 'Retained', 'color' => $fsd, 'tint' => $fsdTint, 'added' => true, 'id' => 'fsd'],
                    ['name' => 'CRD leads', 'leads' => 'crd_leads', 'wait' => 'crd_waiting', 'bought' => 'repeat', 'base' => 'repeat_base', 'rate' => 'repeat_rate', 'lost' => 'crd_lost', 'due' => 'crd_due', 'churn' => 'crd_churn_rate', 'boughtName' => 'Repeat', 'color' => $crd, 'tint' => $crdTint, 'added' => false, 'id' => 'crd'],
                ];
                $sections = ['totals' => 'Totals', 'short' => 'In short', 'rates' => 'Rates', 'months' => 'Month by month', 'sales-split' => 'Seg vs Broadcast', 'products-title' => 'Top products', 'cra-performance' => 'CRAs']
                    + ($exclusive ? ['exclusive' => 'Exclusive leads'] : [])
                    + ['customers' => 'Leads', 'details' => 'Daily / weekly', 'churn' => 'Churn', 'counted' => 'How counted'];
            @endphp

            <header class="flex flex-wrap items-end justify-between gap-4">
                <div class="flex flex-col gap-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 style="margin: 0; font-size: 28px; font-weight: 700">Presentation Board</h1>
                        <span style="border-radius: 999px; background: var(--pb-pill); color: var(--pb-pill-text); padding: 3px 10px; font-size: 12px; font-weight: 600">{{ $monthOptions[$from] }}@if ($from !== $to) – {{ $monthOptions[$to] }}@endif</span>
                        <span style="border-radius: 999px; background: var(--pb-chip); color: var(--pb-soft); padding: 3px 10px; font-size: 12px; font-weight: 600">{{ $monthCount }} {{ Str::plural('month', $monthCount) }}</span>
                        <span class="flex items-center gap-1.5" style="border-radius: 999px; background: var(--pb-pos-bg); color: var(--pb-pos-text); padding: 3px 10px; font-size: 12px; font-weight: 600"><span aria-hidden="true" style="width: 7px; height: 7px; border-radius: 50%; background: {{ $added }}"></span>Based on real POS data</span>
                    </div>
                    <p style="margin: 0; font-size: 14px; {{ $muted }}">Every month in the range side by side: what the CRD team sold, who came in, who was due, and who bought again.</p>
                </div>
                <form method="GET" action="{{ route('presentation-board.index') }}" class="flex flex-wrap items-end gap-2">
                    <input type="hidden" name="period" value="{{ $period }}">
                    @foreach (['from' => 'From', 'to' => 'To'] as $field => $label)
                        <label class="flex flex-col gap-1" style="font-size: 12px; font-weight: 600; {{ $muted }}">{{ $label }}
                            <select name="{{ $field }}" style="height: 44px; min-width: 110px; border: 1px solid var(--pb-field-line); border-radius: 8px; background: var(--pb-field); padding: 0 10px; font-size: 14px; color: var(--pb-text)">
                                @foreach ($monthOptions as $value => $name)
                                    <option value="{{ $value }}" @selected(${$field} === $value)>{{ $name }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endforeach
                    <button type="submit" style="height: 44px; border-radius: 8px; background: var(--pb-btn); color: var(--pb-btn-text); padding: 0 16px; font-size: 14px; font-weight: 700; cursor: pointer">Apply</button>
                    <a href="{{ route('presentation-board.index', ['period' => $period]) }}" style="display: flex; align-items: center; height: 44px; border: 1px solid var(--pb-field-line); border-radius: 8px; padding: 0 14px; font-size: 14px; font-weight: 600; color: var(--pb-text)">Year to date</a>
                </form>
            </header>

            @error('to')
                <p role="alert" style="margin: 0; color: {{ $lost }}; font-size: 14px">{{ $message }}</p>
            @enderror

            <nav aria-label="Board sections" class="flex flex-wrap gap-1.5">
                @foreach ($sections as $anchor => $name)
                    <a href="#{{ $anchor }}" style="padding: 6px 10px; border-radius: 999px; {{ $glass }}; font-size: 12px; font-weight: 600; color: var(--pb-soft)">{{ $name }}</a>
                @endforeach
            </nav>

            <section aria-label="Data status" class="flex flex-wrap items-center gap-x-5 gap-y-2" style="{{ $glass }}; padding: 10px 16px; font-size: 13px">
                <span class="flex items-center gap-2" style="font-weight: 600"><span aria-hidden="true" style="width: 9px; height: 9px; border-radius: 50%; background: {{ $added }}"></span>Updated {{ \Carbon\CarbonImmutable::parse($snapshot['built_at'])->timezone(config('segmentation.timezone'))->format('M j, g:i A') }}</span>
                @if ($isRunning)
                    <span style="color: var(--pb-soft)"><strong style="color: var(--pb-text)">{{ $monthName($lastMonth) }} so far</strong> ({{ $lastMonth['label'] }} runs to the {{ $today->format('jS') }}, striped bars). Its change is compared at its full-month pace: so far × {{ $today->daysInMonth }} ÷ {{ $today->day }}. Rates are never paced.</span>
                @endif
                @unless ($progress['complete'])
                    <span style="font-weight: 700">Backfill ongoing: {{ number_format($progress['done']) }} of {{ number_format($progress['total']) }} page-months loaded.</span>
                    <span>Older months may still go up or down until it finishes.</span>
                    <span aria-hidden="true" class="min-w-20 flex-1" style="height: 8px; border-radius: 999px; background: var(--pb-track)"><span style="display: block; width: {{ $progress['total'] ? round($progress['done'] / $progress['total'] * 100) : 0 }}%; height: 8px; border-radius: 999px; background: {{ $accent }}"></span></span>
                @endunless
            </section>

            {{-- Totals: sales by month, then a tile per figure with its trend and the last month's change against the first. --}}
            <section id="totals" aria-label="Totals" class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(260px, 1fr))">
                @php
                    $salesPeak = max(1, ...array_map(fn (array $month) => $month['gross'], $months));
                    $salesChip = $changeOf($col('gross'), flow: true);
                    $stripes = 'background-image: repeating-linear-gradient(135deg, transparent 0 4px, rgb(255 255 255 / .35) 4px 6px)';
                @endphp
                <div class="flex flex-wrap" style="grid-column: 1 / -1; gap: 16px 24px; {{ $hero }}; padding: 16px">
                    <div class="flex flex-col gap-1.5" style="flex: 1 1 260px">
                        <span style="font-size: 13px; font-weight: 600; color: {{ $accent }}">Overall total sales, every CRA</span>
                        <a href="{{ $ordersUrl() }}" title="See these orders" style="font-size: 44px; line-height: 1.1; font-weight: 700; letter-spacing: -1px; font-variant-numeric: normal; {{ $link }}">{{ $peso($totals['gross']) }}</a>
                        <span style="font-size: 13px; {{ $muted }}">{{ $number($totals['orders']) }} orders · AOV {{ $peso($totals['aov']) }}</span>
                        <span style="{{ $salesChip['style'] ?? $muted }}">{{ $salesChip['text'] ?? $noChange }}</span>
                        @if ($isRunning)
                            <span style="font-size: 12px; {{ $muted }}">{{ $monthName($lastMonth) }} so far {{ $peso($lastMonth['gross']) }} · full-month pace ≈ {{ $peso($paced($lastMonth['gross'])) }}</span>
                        @endif
                        <span class="flex flex-wrap gap-3" style="margin-top: 4px; font-size: 12px; {{ $muted }}">
                            <span class="flex items-center gap-1.5"><span aria-hidden="true" style="{{ $swatch }}; background: {{ $crd }}"></span>Segmentation</span>
                            <span class="flex items-center gap-1.5"><span aria-hidden="true" style="{{ $swatch }}; background: {{ $broadcast }}"></span>Broadcast</span>
                            @if ($totals['untagged_gross'])
                                <span class="flex items-center gap-1.5"><span aria-hidden="true" style="{{ $swatch }}; background: {{ $reactivation }}"></span>Before the tags</span>
                            @endif
                        </span>
                    </div>
                    <div class="flex min-w-0 flex-col gap-1" style="flex: 3 1 420px">
                        <div id="board-read-sales" data-read-default="Point at a month for its sales" style="min-height: 18px; font-size: 13px; font-weight: 600">Point at a month for its sales</div>
                        <div data-chart style="{{ $monthGrid }}; align-items: end; height: 190px">
                            @foreach ($months as $month)
                                @php
                                    $read = $month['label'].': '.$peso($month['gross']).' · '.$number($month['orders']).' orders · AOV '.$peso($month['aov']);
                                    $isOpen = $isRunning && $month['key'] === $lastKey;
                                @endphp
                                <a href="{{ $ordersUrl($month['key']) }}" data-point="{{ $month['key'] }}" data-read="{{ $read }}" data-read-target="board-read-sales" title="{{ $read }}. Click for the orders." class="flex h-full min-w-0 flex-col items-center justify-end gap-1" style="background: transparent; border-radius: 6px; color: inherit">
                                    <span style="font-size: 12px; font-weight: 700; white-space: nowrap">{{ $everyValue || $month['gross'] == $salesPeak || $month['key'] === $lastKey ? $millions($month['gross']) : '' }}</span>
                                    <span class="flex flex-col-reverse overflow-hidden" style="width: 100%; max-width: 64px; height: {{ round($month['gross'] / $salesPeak * 82) }}%; border-radius: 6px 6px 0 0">
                                        @foreach ($hasSplit ? [['sc_gross', $crd], ['bc_gross', $broadcast], ['untagged_gross', $reactivation]] : [['gross', $crd]] as [$part, $color])
                                            @if ($month['gross'] > 0 && $month[$part] > 0)
                                                <span style="display: block; flex: {{ round($month[$part] / $month['gross'], 4) }}; background-color: {{ $color }}; {{ $isOpen ? $stripes : '' }}"></span>
                                            @endif
                                        @endforeach
                                    </span>
                                </a>
                            @endforeach
                        </div>
                        <div aria-hidden="true" style="{{ $monthGrid }}; padding-top: 4px; border-top: 1px solid var(--pb-line2)">
                            @foreach ($months as $month)
                                <span class="flex flex-col items-center text-center" style="min-width: 0"><span style="font-size: 12px; font-weight: 700; color: var(--pb-soft)">{{ $monthName($month) }}</span><span style="font-size: 11px; white-space: nowrap; {{ $muted }}">{{ $everyValue ? $compact($month['orders']).' orders' : '' }}</span></span>
                            @endforeach
                        </div>
                    </div>
                </div>

                @foreach ($tiles as $tile)
                    @php
                        $values = $tile['values'];
                        $last = $values[$monthCount - 1] ?? null;
                        $tileChip = $changeOf($values, $tile['rate'] ?? false, $tile['flow'] ?? false, false, $tile['neutral'] ?? false);
                    @endphp
                    <div class="pb-tile flex flex-col gap-1.5" style="position: relative; {{ $glass }}; padding: 14px 16px">
                        <span class="flex flex-wrap items-center gap-1.5" style="font-size: 13px; font-weight: 600; {{ $muted }}">
                            <span aria-hidden="true" style="width: 9px; height: 9px; border-radius: 50%; background: {{ $tile['dot'] }}"></span>{{ $tile['label'] }}
                            @if ($tile['old'] ?? null)
                                <span style="padding: 1px 6px; border-radius: 999px; background: var(--pb-chip); font-size: 11px">{{ $tile['old'] }}</span>
                            @endif
                            @if ($tile['info'] ?? null)
                                <span class="pb-tip" style="position: relative; display: inline-flex">
                                    <button type="button" aria-label="About {{ $tile['label'] }}" aria-describedby="tip-{{ Str::slug($tile['label']) }}" style="display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; padding: 0; border: 1px solid var(--pb-field-line); border-radius: 50%; background: var(--pb-field); color: var(--pb-muted); cursor: help">
                                        <svg viewBox="0 0 16 16" aria-hidden="true" style="width: 12px; height: 12px"><circle cx="8" cy="4.2" r="1.1" style="fill: currentColor"></circle><path d="M8 7v5.2" style="stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; fill: none"></path></svg>
                                    </button>
                                    <span role="tooltip" id="tip-{{ Str::slug($tile['label']) }}" class="pb-tip-box" style="position: absolute; top: 26px; left: -12px; z-index: 20; width: 300px; padding: 12px 14px; border-radius: 12px; background: var(--pb-solid); border: 1px solid var(--pb-line2); box-shadow: 0 10px 30px rgb(0 0 0 / .18); font-size: 12px; font-weight: 400; line-height: 1.5; color: var(--pb-soft)">
                                        <strong style="display: block; margin-bottom: 2px; font-size: 13px; color: var(--pb-text)">{{ $tile['label'] }} ({{ $tile['old'] }})</strong>
                                        @foreach ($tile['info'] as $line)
                                            <span style="display: block; margin-top: 4px">{{ $line }}</span>
                                        @endforeach
                                    </span>
                                </span>
                            @endif
                        </span>
                        @if ($tile['url'] ?? null)
                            <a href="{{ $tile['url'] }}" title="See these customers" style="font-size: 28px; line-height: 1.1; font-weight: 700; letter-spacing: -.5px; font-variant-numeric: normal; {{ $link }}">{{ $tile['value'] }}</a>
                        @else
                            <span style="font-size: 28px; line-height: 1.1; font-weight: 700; letter-spacing: -.5px; font-variant-numeric: normal">{{ $tile['value'] }}@if ($tile['valueNote'] ?? null) <span style="font-size: 13px; font-weight: 600; letter-spacing: 0; {{ $muted }}">{{ $tile['valueNote'] }}</span>@endif</span>
                        @endif
                        @foreach ($tile['parts'] ?? [] as [$partName, $partColor, $part])
                            <span class="flex flex-wrap items-baseline gap-x-1.5" style="font-size: 13px">
                                <span aria-hidden="true" style="width: 7px; height: 7px; border-radius: 50%; background: {{ $partColor }}; align-self: center"></span>
                                <span style="{{ $muted }}">{{ $partName }}</span>
                                <strong style="font-size: 15px">{{ $percent($part['rate']) }}</strong>
                                <span style="font-size: 12px; {{ $muted }}">{{ $number($part['customers']) }} of {{ $number($part['base']) }}</span>
                            </span>
                        @endforeach
                        <span style="font-size: 13px; {{ $muted }}">{{ $tile['sub'] }}</span>
                        {!! $sparkline($values, $tile['dot'], $isRunning && ($tile['flow'] ?? false)) !!}
                        {!! $valueRow($values, $tile['format']) !!}
                        <span style="{{ $tileChip['style'] ?? 'font-size: 12px; '.$muted }}">{{ $tileChip['text'] ?? $noChange }}</span>
                    </div>
                @endforeach
            </section>

            <details id="journey" style="{{ $glass }}">
                <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2" style="{{ $head }}; border-radius: 16px">
                    <h2 style="{{ $h2 }}"><span aria-hidden="true" class="pb-chevron" style="display: inline-block; margin-right: 6px; font-size: 12px; {{ $muted }}">▸</span>How a customer moves through the numbers</h2>
                    <span style="font-size: 13px; {{ $muted }}">New customers vs due to reorder · click to show</span>
                </summary>
                <div class="flex flex-col gap-3" style="padding: 14px 16px 16px">
                    <ol class="grid gap-2.5" style="margin: 0; padding: 0; list-style: none; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr))">
                        @foreach ([
                            ['1', 'Month of first delivery', 'New customer', $added, 'Buys from FSD for the first time.', 'Old name: Acquired leads'],
                            ['2', 'Month their supply runs out', 'Due to reorder · FSD', $fsd, 'Est. out-of-stock date lands this month, no CRA yet. Goes on a CRA list.', 'Old name: FSD leads'],
                            ['3', 'Same, after a CRA sold to them', 'Due to reorder · CRD', $crd, "Already a CRA's customer, running out again.", 'Old name: CRD leads'],
                            ['4', 'When they buy again', 'Bought again', $accent, 'FSD retained or CRD repeat. No reorder 30 days after a CRA reached them = churned.', 'Retained · Repeat · Churn'],
                        ] as [$step, $when, $name, $color, $what, $was])
                            <li class="flex flex-col gap-1.5" style="{{ $cell }}; padding: 12px 14px">
                                <span class="flex items-center gap-2" style="font-size: 12px; font-weight: 700; {{ $muted }}"><span class="flex items-center justify-center" style="width: 22px; height: 22px; border-radius: 50%; background: var(--pb-chip); color: var(--pb-text)">{{ $step }}</span>{{ $when }}</span>
                                <span class="flex items-center gap-1.5" style="font-size: 15px; font-weight: 700"><span aria-hidden="true" style="width: 9px; height: 9px; border-radius: 50%; background: {{ $color }}"></span>{{ $name }}</span>
                                <span style="font-size: 13px; line-height: 1.45; color: var(--pb-soft)">{{ $what }}</span>
                                <span style="font-size: 12px; {{ $muted }}">{{ $was }}</span>
                            </li>
                        @endforeach
                    </ol>
                    <p style="{{ $note }}; color: var(--pb-soft)"><strong style="color: {{ $text }}">Example:</strong> Ana gets her first FSD delivery on Oct 5, a 45-day supply. She is a <strong>new customer in October</strong>. Her supply runs out around Nov 18, so she is <strong>due to reorder (FSD) in November</strong>. If she buys again, she counts as <strong>bought again (FSD retained)</strong>.</p>
                </div>
            </details>

            @if ($insights)
                <section id="short" aria-labelledby="short-title" style="{{ $glass }}; padding: 12px 16px">
                    <h2 id="short-title" style="margin: 0 0 6px; font-size: 13px; font-weight: 700; color: {{ $accent }}">In short <span style="font-weight: 500; {{ $muted }}">· click a dotted number to see the customers behind it</span></h2>
                    <ul class="grid gap-x-6 gap-y-1" style="margin: 0; padding: 0; list-style: none; font-size: 14px; grid-template-columns: repeat(auto-fit, minmax(380px, 1fr))">
                        @foreach ($insights as $line)
                            <li>{{ $line }}</li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <section id="rates" aria-labelledby="rates-title" style="{{ $glass }}">
                <header class="flex flex-wrap items-center justify-between gap-2" style="{{ $head }}">
                    <h2 id="rates-title" style="{{ $h2 }}">Conversion, retain and repeat by month</h2>
                    <span id="board-read-rates" data-read-default="Point at a month for its rates" style="font-size: 13px; {{ $muted }}">Point at a month for its rates</span>
                </header>
                <div class="flex flex-wrap gap-5" style="padding: 16px">
                    <div class="flex flex-col gap-4" style="flex: 1 1 280px">
                        @foreach ($rateCards as $card)
                            @php
                                $rateChip = $changeOf($col($card['key']), rate: true);
                            @endphp
                            <div class="flex flex-col gap-1">
                                <div class="flex items-baseline justify-between gap-3">
                                    <span class="flex items-center gap-2" style="font-size: 14px; font-weight: 600"><span aria-hidden="true" style="width: 14px; height: 3px; border-radius: 2px; background: {{ $card['color'] }}"></span>{{ $card['label'] }}</span>
                                    <span style="font-size: 26px; font-weight: 700; font-variant-numeric: normal">{{ $percent($card['value']) }}</span>
                                </div>
                                <span style="font-size: 13px; {{ $muted }}">{{ $card['sub'] }}</span>
                                <span style="{{ $rateChip['style'] ?? 'font-size: 12px; '.$muted }}">{{ $rateChip['text'] ?? $noChange }}</span>
                            </div>
                        @endforeach
                        <p style="{{ $note }}">Each month matches its Dashboard tiles.</p>
                    </div>
                    <div class="min-w-0" style="flex: 999 1 560px">
                        <x-presentation-board.line-chart
                            :months="$months" read-target="board-read-rates"
                            :split="$trackerAt ? ['at' => $trackerAt, 'before' => 'Deliveries', 'after' => 'Tracker'] : null"
                            :series="[['key' => 'conversion_rate', 'color' => $accent, 'name' => 'Conversion'], ['key' => 'retained_rate', 'color' => $fsd, 'name' => 'Retain'], ['key' => 'repeat_rate', 'color' => $crd, 'name' => 'Repeat']]"
                            :read="fn (array $month) => $month['label'].': conversion '.($month['conversion'] === null ? 'no tracker data' : $of($month['conversion']['all']['customers'], $month['conversion']['all']['base'])).' · retain '.$of($month['retained'], $month['retained_base']).' · repeat '.$of($month['repeat'], $month['repeat_base'])" />
                    </div>
                </div>
            </section>

            {{-- Month by month: months across, figures down, so any number of months reads the same; the first column stays put. --}}
            <section id="months" aria-labelledby="months-title" style="{{ $glass }}">
                <header class="flex flex-wrap items-center justify-between gap-2" style="{{ $head }}">
                    <h2 id="months-title" style="{{ $h2 }}">Month by month</h2>
                    <span style="font-size: 13px; {{ $muted }}">Months across, figures down · scrolls sideways for long ranges @if ($totals['fsd_waiting'] || $totals['crd_waiting'])· + = waiting for page check, not in the lead count @endif</span>
                </header>
                @php
                    $sticky = 'position: sticky; left: 0; z-index: 1; background: var(--pb-solid); text-align: left; white-space: nowrap';
                    $key = fn (string $color) => '<span aria-hidden="true" style="display: inline-block; width: 8px; height: 8px; margin-right: 6px; border-radius: 50%; background: '.$color.'"></span>';
                    $waiting = fn (int|float $count, ?string $url = null) => $count ? ($url ? '<a href="'.e($url).'" style="'.$small.'; '.$link.'">+'.e($number($count)).'</a>' : '<span style="'.$small.'">+'.e($number($count)).'</span>') : '';
                    $ledgerRows = [
                        ['Due to reorder · FSD', $fsd, fn (array $m) => '<a href="'.e($listUrl('fsd_leads', $m['key'])).'" style="'.$link.'">'.e($number($m['fsd_leads'])).'</a>'.$waiting($m['fsd_waiting'], $listUrl('fsd_waiting', $m['key'])), e($number($totals['fsd_leads'])).$waiting($totals['fsd_waiting']), ['fsd_leads', false, false, false, true]],
                        ['Due to reorder · CRD', $crd, fn (array $m) => '<a href="'.e($listUrl('crd_leads', $m['key'])).'" style="'.$link.'">'.e($number($m['crd_leads'])).'</a>'.$waiting($m['crd_waiting'], $listUrl('crd_waiting', $m['key'])), e($number($totals['crd_leads'])).$waiting($totals['crd_waiting']), ['crd_leads', false, false, false, true]],
                        ['New customers', $added, fn (array $m) => '<a href="'.e($listUrl('added', $m['key'])).'" style="'.$link.'">'.e($number($m['added'])).'</a>'.$waiting($m['added_waiting'], $listUrl('added_waiting', $m['key'])), e($number($totals['added'])).$waiting($totals['added_waiting']), ['added', false, true]],
                        ['Orders', $accent, fn (array $m) => '<a href="'.e($ordersUrl($m['key'])).'" style="'.$link.'">'.e($number($m['orders'])).'</a>', e($number($totals['orders'])), ['orders', false, true]],
                        ['Gross sales', $accent, fn (array $m) => e($peso($m['gross'])), e($peso($totals['gross'])), ['gross', false, true]],
                        ['AOV', $accent, fn (array $m) => e($peso($m['aov'])), e($peso($totals['aov'])), ['aov', false, false]],
                        ['FSD retained', $fsd, fn (array $m) => '<a href="'.e($listUrl('retained', $m['key'])).'" style="'.$link.'">'.e($number($m['retained'])).'</a>', e($number($totals['retained'])), ['retained', false, true]],
                        ['Retained %', $fsd, fn (array $m) => e($percent($m['retained_rate'])).'<span style="'.$small.'">of '.e($number($m['retained_base'])).'</span>', e($percent($totals['retained_rate'])).'<span style="'.$small.'">of '.e($number($totals['retained_base'])).'</span>', ['retained_rate', true, false]],
                        ['CRD repeat', $crd, fn (array $m) => '<a href="'.e($listUrl('repeat', $m['key'])).'" style="'.$link.'">'.e($number($m['repeat'])).'</a>', e($number($totals['repeat'])), ['repeat', false, true]],
                        ['Repeat %', $crd, fn (array $m) => e($percent($m['repeat_rate'])).'<span style="'.$small.'">of '.e($number($m['repeat_base'])).'</span>', e($percent($totals['repeat_rate'])).'<span style="'.$small.'">of '.e($number($totals['repeat_base'])).'</span>', ['repeat_rate', true, false]],
                        ['Conversion', $accent, fn (array $m) => e($percent($m['conversion_rate'])), e($percent($totals['conversion_rate'])), ['conversion_rate', true, false]],
                        ['Net', $added, fn (array $m) => '<span style="font-weight: 700; color: '.($m['net'] < 0 ? $lost : $added).'">'.e($signed($m['net'])).'</span>', '<span style="font-weight: 700; color: '.($totals['net'] < 0 ? $lost : $added).'">'.e($signed($totals['net'])).'</span>', null],
                        ['Churn', $lost, fn (array $m) => '<a href="'.e($churnUrl('lost', 'all', $m['key'])).'" style="'.$link.'">'.e($number($m['all_lost'])).'</a>'.e($star($m)).'<span style="'.$small.'">'.e($percent($m['churn_rate'])).' of '.e($number($m['all_due'])).' due</span>', e($number($totals['all_lost'])).'<span style="'.$small.'">'.e($percent($totals['churn_rate'])).' '.e($totals['churn_label']).'</span>', ['all_lost', false, false, true]],
                    ];
                @endphp
                <div style="padding: 12px 16px 6px">
                    <div class="overflow-x-auto" style="border: 1px solid var(--pb-line); border-radius: 12px">
                        <table style="width: 100%; border-collapse: collapse; font-size: 13px">
                            <thead>
                                <tr style="text-align: right; font-size: 12px; {{ $muted }}">
                                    <th scope="col" style="{{ $sticky }}; min-width: 170px; padding: 8px 10px; font-weight: 600; border-bottom: 1px solid var(--pb-line2)">Figure</th>
                                    @foreach ($months as $month)
                                        <th scope="col" style="padding: 8px 10px; white-space: nowrap; border-bottom: 1px solid var(--pb-line2); font-weight: {{ $month['key'] === $lastKey ? 700 : 600 }}; {{ $month['key'] === $lastKey ? 'color: var(--pb-text); background: var(--pb-on)' : '' }}">{{ $monthName($month) }}</th>
                                    @endforeach
                                    <th scope="col" style="padding: 8px 10px; font-weight: 700; color: var(--pb-text); border-bottom: 1px solid var(--pb-line2)">Total</th>
                                    @if ($firstMonth)
                                        <th scope="col" style="padding: 8px 10px; white-space: nowrap; font-weight: 600; border-bottom: 1px solid var(--pb-line2)">{{ $monthName($lastMonth) }} vs {{ $firstMonth['label'] }}</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($ledgerRows as [$label, $color, $cellFor, $total, $change])
                                    <tr style="text-align: right; {{ $rowLine }}">
                                        <th scope="row" style="{{ $sticky }}; padding: 8px 10px; font-weight: 600">{!! $key($color) !!}{{ $label }}</th>
                                        @foreach ($months as $month)
                                            <td style="padding: 8px 10px; white-space: nowrap">{!! $cellFor($month) !!}</td>
                                        @endforeach
                                        <td style="padding: 8px 10px; white-space: nowrap; font-weight: 700">{!! $total !!}</td>
                                        @if ($firstMonth)
                                            @php
                                                [$changeKey, $changeRate, $changeFlow, $changeLower, $changeNeutral] = [...($change ?? [null]), false, false, false, false];
                                                $rowChip = $changeKey ? $chip($changeFlow ? $paced($lastMonth[$changeKey]) : $lastMonth[$changeKey], $firstMonth[$changeKey], $changeRate, $changeLower, $changeNeutral) : null;
                                            @endphp
                                            <td style="padding: 8px 10px; white-space: nowrap">@if ($rowChip)<span style="{{ $rowChip['style'] }}">{{ $rowChip['text'] }}</span>@else<span style="{{ $muted }}">—</span>@endif</td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="flex flex-col gap-1" style="padding: 8px 16px 14px">
                    @if ($firstMonth)
                        <p style="{{ $note }}"><strong style="color: {{ $text }}">{{ $monthName($lastMonth) }} vs {{ $firstMonth['label'] }}</strong>: the last month picked against the first, as the chips above. Counts in %, rates in points (pts).@if ($isRunning) Counts that build up day by day (new customers, orders, sales, retained, repeat) use {{ $lastMonth['label'] }}'s full-month pace.@endif</p>
                    @endif
                    <p style="{{ $note }}"><strong style="color: {{ $text }}">New customers</strong> (was Acquired leads): a customer's first delivery on record, from FSD. <strong style="color: {{ $text }}">Net</strong>: new customers minus churned. <strong style="color: {{ $text }}">Churn</strong>: churned customers, with the rate of those due.</p>
                    @if ($hasPartial)
                        <p style="{{ $note }}">* Churn is partial before July: tracker leads start in June. The total's rate uses {{ $totals['churn_label'] }} only.</p>
                    @endif
                </div>
            </section>

            <section id="sales-split" aria-labelledby="sales-split-title" style="{{ $glass }}">
                @php
                    $untaggedColor = $reactivation;
                    $salesTypes = [
                        ['key' => 'sc', 'name' => 'Segmentation', 'color' => $crd],
                        ['key' => 'bc', 'name' => 'Broadcast', 'color' => $broadcast],
                    ];
                    $tagged = $totals['sc_gross'] + $totals['bc_gross'];
                    $splitRead = $tagged ? 'Segmentation '.number_format($totals['sc_gross'] / $tagged * 100, 1).'% · Broadcast '.number_format($totals['bc_gross'] / $tagged * 100, 1).'% of tagged sales' : 'No tagged sales in these months';
                    $tagsFrom = \Carbon\CarbonImmutable::parse(config('segmentation.conversion_tags_from'))->format('M j');
                    $splitParts = array_map(fn (array $type) => [$type['name'], $totals[$type['key'].'_gross'], $type['color'], $peso($totals[$type['key'].'_gross'])], $salesTypes);
                @endphp
                <header class="flex flex-wrap items-center justify-between gap-2" style="{{ $head }}">
                    <h2 id="sales-split-title" style="{{ $h2 }}">Segmentation vs Broadcast sales</h2>
                    <span id="board-read-split" data-read-default="{{ $splitRead }}" style="font-size: 13px; {{ $muted }}">{{ $splitRead }}</span>
                </header>
                @if (! $hasSplit)
                    <p role="status" style="{{ $note }}; padding: 16px">These figures appear after the board's next refresh (every {{ \App\Services\PresentationBoard::EVERY_HOURS }} hours). Total sales above already include them.</p>
                @else
                <div class="flex flex-wrap gap-5" style="padding: 16px">
                    <div class="flex flex-col gap-4" style="flex: 1 1 280px">
                        @if ($tagged > 0)
                            <div class="flex justify-center">{!! $donut($splitParts, $millions($tagged), 'tagged sales', $splitRead, 170) !!}</div>
                        @endif
                        @foreach ($salesTypes as $type)
                            <div class="flex flex-col gap-1">
                                <div class="flex items-baseline justify-between gap-3">
                                    <span class="flex items-center gap-2" style="font-size: 14px; font-weight: 600"><span aria-hidden="true" style="{{ $swatch }}; background: {{ $type['color'] }}"></span>{{ $type['name'] }}</span>
                                    <span style="font-size: 26px; font-weight: 700; font-variant-numeric: normal">{{ $peso($totals[$type['key'].'_gross']) }}</span>
                                </div>
                                <span style="font-size: 13px; {{ $muted }}">{{ $tagged ? number_format($totals[$type['key'].'_gross'] / $tagged * 100, 1).'%' : '—' }} of tagged sales · {{ $number($totals[$type['key'].'_orders']) }} {{ Str::plural('order', $totals[$type['key'].'_orders']) }} · AOV {{ $peso($totals[$type['key'].'_aov']) }}</span>
                            </div>
                        @endforeach
                        @if ($totals['untagged_gross'])
                            <p style="{{ $note }}"><span aria-hidden="true" style="display: inline-block; {{ $swatch }}; margin-right: 5px; background: {{ $untaggedColor }}"></span><strong style="color: {{ $text }}">{{ $peso($totals['untagged_gross']) }}</strong> ({{ $number($totals['untagged_orders']) }} {{ Str::plural('order', $totals['untagged_orders']) }}) sold before the tags started on {{ $tagsFrom }}, so not split. Shares are of tagged sales.</p>
                        @endif
                        <p style="{{ $note }}">By each order's CRD - SEGMENTATION / CRD - BROADCAST tag; the CVR Tracker's Type of Order wins over it. Together they make the total sales above.</p>
                    </div>
                    <div class="flex min-w-0 flex-col gap-2" data-chart style="flex: 999 1 560px">
                        <div class="mb-1 flex flex-wrap gap-3" style="font-size: 13px; {{ $muted }}">
                            @foreach ($salesTypes as $type)
                                <span class="flex items-center gap-1.5"><span aria-hidden="true" style="{{ $swatch }}; background: {{ $type['color'] }}"></span>{{ $type['name'] }}</span>
                            @endforeach
                            @if ($totals['untagged_gross'])
                                <span class="flex items-center gap-1.5"><span aria-hidden="true" style="{{ $swatch }}; background: {{ $untaggedColor }}"></span>Before the tags</span>
                            @endif
                            <span>Bar = each month's sales, by share</span>
                        </div>
                        @foreach ($months as $month)
                            @php
                                $monthTagged = $month['sc_gross'] + $month['bc_gross'];
                                $read = $month['label'].': Segmentation '.$peso($month['sc_gross']).' ('.$number($month['sc_orders']).' orders) · Broadcast '.$peso($month['bc_gross']).' ('.$number($month['bc_orders']).' orders)'
                                    .($monthTagged ? ' · Broadcast '.number_format($month['bc_gross'] / $monthTagged * 100, 1).'%' : '')
                                    .($month['untagged_gross'] ? ' · before the tags '.$peso($month['untagged_gross']) : '');
                                $monthAll = $monthTagged + $month['untagged_gross'];
                            @endphp
                            <a href="{{ $ordersUrl($month['key']) }}" data-point="split-{{ $month['key'] }}" data-read="{{ $read }}" data-read-target="board-read-split" title="{{ $read }}. Click for the orders." class="grid items-center gap-2.5" style="grid-template-columns: 48px minmax(0, 1fr) 84px; padding: 3px 4px; border-radius: 6px; background: transparent; color: inherit; font-size: 12px">
                                <span style="font-weight: 700; color: var(--pb-soft)">{{ $month['label'] }}</span>
                                <span class="flex overflow-hidden" style="height: 22px; border-radius: 6px; background: var(--pb-track)">
                                    @foreach ([...$salesTypes, ['key' => 'untagged', 'color' => $untaggedColor]] as $type)
                                        @if ($monthAll > 0 && $month[$type['key'].'_gross'] > 0)
                                            @php
                                                $share = $month[$type['key'].'_gross'] / $monthAll * 100;
                                            @endphp
                                            <span class="flex items-center justify-center" style="width: {{ round($share, 2) }}%; background: {{ $type['color'] }}; font-size: 11px; font-weight: 700; color: #fff">{{ $share >= 12 ? number_format($share).'%' : '' }}</span>
                                        @endif
                                    @endforeach
                                </span>
                                <span style="text-align: right; font-weight: 700">{{ $millions($monthAll) }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
                @endif
            </section>

            <section aria-labelledby="products-title" class="min-w-0" style="{{ $glass }}">
                @php
                    $othersName = \App\Services\PresentationBoard::OTHERS;
                    $listed = collect($products)->reject(fn (array $product) => $product['name'] === $othersName)->values();
                    $productPeak = max(1, $listed->max('sales') ?? 1);
                    $upsell = $added;
                    // A product's Upsell % is what was upsold on its orders; for Others and its items, the share of their own sales that came as an upsell.
                    $upsellRate = fn (array $row, bool $own) => $own ? ($row['value'] ? $row['upsell_value'] / $row['value'] : 0.0) : ($row['sales'] ? $row['upsold_value'] / $row['sales'] : 0.0);
                    $upsellPill = fn (float $rate) => $rate > 0
                        ? '<span style="justify-self: end; padding: 2px 8px; border-radius: 999px; font-size: 12px; font-weight: 700; background: var(--pb-good-bg); color: '.$upsell.'">'.($rate < .001 ? '<0.1%' : number_format($rate * 100, 1).'%').'</span>'
                        : '<span style="justify-self: end; font-size: 12px; '.$muted.'">0%</span>';
                    $chevron = '<span aria-hidden="true" class="pb-chevron" style="display: inline-block; width: 14px; font-size: 11px; transition: transform .15s; '.$muted.'">▸</span>';
                    $monthlySales = fn (string $name) => array_map(fn (array $month) => $productMonths[$month['key']][$name]['sales'] ?? 0.0, $months);
                    $monthlyOrders = fn (string $name) => array_map(fn (array $month) => $productMonths[$month['key']][$name]['orders'] ?? 0, $months);
                    $rankIn = fn (string $month, string $name) => isset($productMonths[$month][$name]) ? collect($productMonths[$month])->filter(fn (array $figures) => $figures['sales'] > $productMonths[$month][$name]['sales'])->count() + 1 : null;
                    $donutParts = $listed->take(6)->values()->map(fn (array $product, int $index) => [$product['name'], $product['sales'], $palette[$index], $peso($product['sales'])])->all();
                    $rest = $listed->slice(6)->sum('sales') + (collect($products)->firstWhere('name', $othersName)['sales'] ?? 0);
                    if ($rest > 0) {
                        $donutParts[] = [$listed->count() > 6 ? 'Next '.($listed->count() - 6).' products and Others' : 'Others', $rest, $reactivation, $peso($rest)];
                    }
                    $donutTotal = array_sum(array_column($donutParts, 1));
                @endphp
                <header class="flex flex-wrap items-center justify-between gap-2" style="{{ $head }}">
                    <h2 id="products-title" style="{{ $h2 }}">Top products</h2>
                    <form method="GET" action="{{ route('presentation-board.index') }}#products-title" class="flex flex-wrap items-center gap-1.5" style="font-size: 12px">
                        <input type="hidden" name="from" value="{{ $from }}"><input type="hidden" name="to" value="{{ $to }}"><input type="hidden" name="period" value="{{ $period }}">
                        @foreach (['products_from' => ['From', $topProducts['from']], 'products_to' => ['To', $topProducts['to']]] as $field => [$label, $value])
                            <label class="flex items-center gap-1" style="{{ $muted }}">{{ $label }}
                                <input type="date" id="{{ $field }}" name="{{ $field }}" value="{{ $value }}" min="{{ $topProducts['first_day'] }}" max="{{ $topProducts['last_day'] }}" style="height: 32px; border: 1px solid var(--pb-field-line); border-radius: 8px; background: var(--pb-field); padding: 0 6px; font-size: 13px; color: var(--pb-text)">
                            </label>
                        @endforeach
                        <button type="submit" class="cursor-pointer" style="height: 32px; border-radius: 8px; background: var(--pb-btn); color: var(--pb-btn-text); padding: 0 10px; font-weight: 700">Apply</button>
                        @if ($topProducts['picked'])
                            <a href="{{ route('presentation-board.index', ['from' => $from, 'to' => $to, 'period' => $period]) }}#products-title" style="font-weight: 600; {{ $link }}">Same as the months</a>
                        @endif
                    </form>
                    <div role="group" aria-label="Sort products" class="inline-flex" style="padding: 2px; border: 1px solid var(--pb-line2); border-radius: 999px; font-size: 12px">
                        <button type="button" data-product-sort="sales" aria-pressed="true" class="cursor-pointer" style="padding: 3px 10px; border-radius: 999px">By sales</button>
                        <button type="button" data-product-sort="upsell" aria-pressed="false" class="cursor-pointer" style="padding: 3px 10px; border-radius: 999px">By upsell %</button>
                    </div>
                </header>
                <div class="flex flex-wrap items-center gap-x-5 gap-y-1" style="padding: 12px 16px 0; font-size: 13px; {{ $muted }}">
                    <span><strong style="color: {{ $text }}">{{ \Carbon\CarbonImmutable::parse($topProducts['from'])->format('M j') }} – {{ \Carbon\CarbonImmutable::parse($topProducts['to'])->format('M j, Y') }}</strong> · gross {{ $peso($topProducts['gross']) }}</span>
                    <span class="flex items-center gap-1.5"><span style="{{ $swatch }}; background: {{ $upsell }}"></span>Upsell <strong style="color: {{ $text }}">{{ $peso($topProducts['upsell_value']) }}</strong> · {{ $number($topProducts['upsell_orders']) }} {{ Str::plural('order', $topProducts['upsell_orders']) }} · {{ $topProducts['gross'] ? number_format($topProducts['upsell_value'] / $topProducts['gross'] * 100, 1).'%' : '—' }} of gross</span>
                </div>
                @if ($donutTotal > 0)
                    <div class="flex flex-wrap items-center gap-4" style="padding: 12px 16px 0">
                        {!! $donut($donutParts, $millions($donutTotal), 'sales', 'Donut: each top product\'s share of these dates\' product sales') !!}
                        <ul class="grid gap-x-4 gap-y-1.5" style="flex: 1 1 260px; margin: 0; padding: 0; list-style: none; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); font-size: 12px">
                            @foreach ($donutParts as [$name, $value, $color])
                                <li class="flex items-center gap-1.5"><span aria-hidden="true" style="{{ $swatch }}; flex: none; background: {{ $color }}"></span><span style="flex: 1; min-width: 0; overflow-wrap: anywhere">{{ $name }}</span><strong>{{ number_format($value / $donutTotal * 100, 1) }}%</strong></li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @php
                    $rowColumns = 'display: grid; grid-template-columns: 22px minmax(130px, 190px) minmax(120px, 1fr) 104px 64px 64px minmax(110px, 150px); align-items: center; gap: 10px';
                    $h3 = 'margin: 0; font-size: 12px; font-weight: 700; letter-spacing: .02em; text-transform: uppercase; color: var(--pb-muted)';
                    $miniCell = 'padding: 4px 0 4px 10px; white-space: nowrap';
                @endphp
                <div class="flex flex-col gap-2" style="padding: 12px 16px 16px; font-size: 13px">
                    <div class="flex flex-wrap gap-x-4 gap-y-1" style="font-size: 12px; color: var(--pb-soft)">
                        <span class="flex items-center gap-1.5"><span aria-hidden="true" style="{{ $swatch }}; background: {{ $crd }}"></span>Main / reorder</span>
                        <span class="flex items-center gap-1.5"><span aria-hidden="true" style="{{ $swatch }}; background: {{ $upsell }}"></span>Upsell</span>
                        <span style="{{ $muted }}">Right: each product's sales by month, {{ $months[0]['label'] }}–{{ $monthName($lastMonth) }}</span>
                    </div>
                    <div class="overflow-x-auto">
                        <div style="min-width: 760px">
                            <div style="{{ $rowColumns }}; padding: 0 10px 6px 32px; font-size: 11px; font-weight: 600; text-align: right; {{ $muted }}">
                                <span></span><span class="text-left">Product</span><span class="text-left">Main / upsell</span><span>Sales</span><span>Orders</span><span>Qty</span><span style="text-align: center">By month</span>
                            </div>
                            <div data-product-rows role="region" aria-label="Product list, scrolls" tabindex="0" class="flex flex-col gap-2" style="max-height: 470px; overflow-y: auto; padding: 2px 4px 2px 0">
                                @foreach ($products as $rank => $product)
                                    @php
                                        $isOthers = $product['name'] === $othersName;
                                        $rate = $upsellRate($product, $isOthers);
                                        $byMonth = $isOthers ? [] : $monthlySales($product['name']);
                                        $hasMonths = array_sum($byMonth) > 0;
                                        $hasDrawer = $product['upsell_value'] > 0 || $product['upsold'] || ($isOthers && $others) || $hasMonths;
                                        $mainWidth = min(100, round(max(0, max($product['sales'], $product['value']) - $product['upsell_value']) / $productPeak * 100, 2));
                                        $upWidth = min(100 - $mainWidth, round($product['upsell_value'] / $productPeak * 100, 2));
                                        $upsoldPeak = max(1, collect($product['upsold'])->max('value') ?? 1);
                                    @endphp
                                    @if ($isOthers)
                                        <details data-others style="border-radius: 10px; background: var(--pb-cell); border: 1px dashed var(--pb-line2)" @if (! $others) data-empty @endif>
                                            <summary class="{{ $others ? 'cursor-pointer' : '' }} flex items-center gap-2" style="padding: 10px; list-style: none">
                                                {!! $others ? $chevron : '<span style="display: inline-block; width: 14px"></span>' !!}
                                                <strong>Others</strong><span style="{{ $muted }}">· {{ number_format(count($others)) }} {{ Str::plural('item', count($others)) }} not in Product Consumption{{ $others ? ', click to see' : '' }}</span>
                                                <span style="margin-left: auto; font-weight: 700">{{ $peso($product['sales']) }}</span>
                                                <span style="width: 64px; text-align: right; {{ $muted }}">{{ $number($product['orders']) }}</span>
                                            </summary>
                                            @if ($others)
                                                @php
                                                    $otherPeak = max(1, collect($others)->max('sales') ?? 1);
                                                @endphp
                                                <div class="flex flex-col gap-1" style="padding: 0 12px 12px 32px; font-size: 12px">
                                                    @foreach ($others as $other)
                                                        <div style="display: grid; grid-template-columns: minmax(140px, 240px) minmax(0, 1fr) 90px 64px 54px; align-items: center; gap: 8px">
                                                            <span style="overflow-wrap: anywhere">{{ $other['name'] }}</span>
                                                            <span aria-hidden="true" style="display: block; height: 6px; border-radius: 999px; background: var(--pb-track)"><span style="display: block; height: 6px; border-radius: 999px; width: {{ round($other['sales'] / $otherPeak * 100, 2) }}%; background: {{ $reactivation }}"></span></span>
                                                            <span style="text-align: right; font-weight: 700">{{ $peso($other['sales']) }}</span>
                                                            <span style="text-align: right; {{ $muted }}">{{ $number($other['orders']) }} {{ Str::plural('order', $other['orders']) }}</span>
                                                            {!! $upsellPill($upsellRate($other, true)) !!}
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </details>
                                    @else
                                    <details data-product data-sales="{{ round($product['sales'], 2) }}" data-upsell="{{ round($rate, 6) }}" style="border-radius: 10px; background: var(--pb-cell); border: 1px solid var(--pb-line)" @if (! $hasDrawer) data-empty @endif>
                                        <summary class="{{ $hasDrawer ? 'cursor-pointer' : '' }} flex items-center gap-2" style="padding: 8px 10px; list-style: none">
                                            {!! $hasDrawer ? $chevron : '<span style="display: inline-block; width: 14px"></span>' !!}
                                            <span style="flex: 1; min-width: 0; {{ $rowColumns }}">
                                                <span data-rank style="font-weight: 700; {{ $muted }}">{{ $rank + 1 }}</span>
                                                <span style="min-width: 0; font-weight: 700; overflow-wrap: anywhere">{{ $product['name'] }}
                                                    <span style="display: block; font-size: 11px; font-weight: 500; {{ $muted }}">{{ number_format($product['share'] * 100, 1) }}% share</span>
                                                    @if ($product['value'] - $product['sales'] >= 1)
                                                        <span style="display: block; font-size: 11px; font-weight: 500; {{ $muted }}" title="Shecom leaves a TSD add-on next to a CRD product out of gross">Sold for {{ $peso($product['value']) }}</span>
                                                    @endif
                                                </span>
                                                <span class="flex flex-col gap-1" style="min-width: 0">
                                                    <span aria-hidden="true" class="flex overflow-hidden" style="height: 14px; border-radius: 4px; background: var(--pb-track)" title="Main / reorder {{ $peso($product['value'] - $product['upsell_value']) }} · upsell {{ $peso($product['upsell_value']) }}">
                                                        <span style="width: {{ $mainWidth }}%; background: {{ $crd }}"></span><span style="width: {{ $upWidth }}%; background: {{ $upsell }}"></span>
                                                    </span>
                                                    @if ($product['upsold_value'] > 0)
                                                        <span style="font-size: 11px; font-weight: 600; color: {{ $upsell }}">+{{ $pesoShort($product['upsold_value']) }} upsold</span>
                                                    @endif
                                                </span>
                                                <span style="text-align: right; font-weight: 700" title="{{ $peso($product['sales']) }}">{{ $pesoShort($product['sales']) }}
                                                    <span style="display: block; font-size: 11px; font-weight: 500; {{ $muted }}">{{ $rate > 0 ? ($rate < .001 ? '<0.1' : number_format($rate * 100, 1)).'% upsell' : 'no upsell' }}</span>
                                                </span>
                                                <span style="text-align: right">{{ $number($product['orders']) }}</span>
                                                <span style="text-align: right">{{ $number($product['qty']) }}</span>
                                                <span title="{{ $product['name'] }} by month: {{ collect($months)->map(fn (array $month, int $index) => $month['label'].' '.$peso($byMonth[$index] ?? 0))->join(', ') }}">{!! $hasMonths ? $sparkline($byMonth, $crd, $isRunning) : '' !!}</span>
                                            </span>
                                        </summary>
                                        @if ($hasDrawer)
                                            <div class="grid gap-x-6 gap-y-3" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); padding: 4px 12px 14px 32px; font-size: 12px">
                                                @if ($product['upsell_value'] > 0)
                                                <div class="flex min-w-0 flex-col gap-1.5">
                                                        <h3 style="{{ $h3 }}">Sold as</h3>
                                                        <table style="width: 100%; border-collapse: collapse">
                                                            <thead><tr style="text-align: right; {{ $muted }}"><th scope="col" style="text-align: left; font-weight: 600; padding: 3px 0">Type</th><th scope="col" style="font-weight: 600; padding: 3px 0 3px 10px">Orders</th><th scope="col" style="font-weight: 600; padding: 3px 0 3px 10px">Value</th><th scope="col" style="font-weight: 600; padding: 3px 0 3px 10px">Qty</th><th scope="col" style="font-weight: 600; padding: 3px 0 3px 10px">% of value</th></tr></thead>
                                                            <tbody>
                                                                @foreach ([['Main / reorder', $crd, ''], ['Upsell', $upsell, 'upsell_']] as [$label, $color, $prefix])
                                                                    @php
                                                                        $part = $prefix === '' ? ['value' => $product['value'] - $product['upsell_value'], 'orders' => $product['orders'] - $product['upsell_orders'], 'qty' => $product['qty'] - $product['upsell_qty']] : ['value' => $product['upsell_value'], 'orders' => $product['upsell_orders'], 'qty' => $product['upsell_qty']];
                                                                    @endphp
                                                                    <tr style="text-align: right; border-top: 1px solid var(--pb-row)">
                                                                        <th scope="row" style="text-align: left; font-weight: 600; padding: 4px 0"><span class="inline-flex items-center gap-1.5"><span aria-hidden="true" style="{{ $swatch }}; background: {{ $color }}"></span>{{ $label }}</span></th>
                                                                        <td style="{{ $miniCell }}">{{ $number($part['orders']) }}</td><td style="{{ $miniCell }}">{{ $peso($part['value']) }}</td><td style="{{ $miniCell }}">{{ $number($part['qty']) }}</td><td style="{{ $miniCell }}; font-weight: 700">{{ $product['value'] ? number_format($part['value'] / $product['value'] * 100, 1).'%' : '—' }}</td>
                                                                    </tr>
                                                                @endforeach
                                                            </tbody>
                                                        </table>
                                                </div>
                                                @endif
                                                @if ($product['upsold'])
                                                    <div class="flex min-w-0 flex-col gap-1.5">
                                                        <h3 style="{{ $h3 }}">Upsold on {{ $product['name'] }} orders</h3>
                                                        @foreach ($product['upsold'] as $item)
                                                            <div style="display: grid; grid-template-columns: minmax(90px, 150px) minmax(0, 1fr) 76px 48px; align-items: center; gap: 4px 8px">
                                                                <span style="font-weight: 600; overflow-wrap: anywhere">{{ $item['name'] }}</span>
                                                                <span aria-hidden="true" style="display: block; height: 8px; border-radius: 999px; background: var(--pb-track)"><span style="display: block; height: 8px; border-radius: 999px; width: {{ round($item['value'] / $upsoldPeak * 100, 2) }}%; background: {{ $upsell }}"></span></span>
                                                                <span style="text-align: right; font-weight: 700" title="{{ $peso($item['value']) }}">{{ $pesoShort($item['value']) }}</span>
                                                                <span style="text-align: right; {{ $muted }}">{{ $product['sales'] ? number_format($item['value'] / $product['sales'] * 100, 1).'%' : '—' }}</span>
                                                                <span style="grid-column: 2 / -1; margin-top: -3px; font-size: 11px; {{ $muted }}">{{ $number($item['orders']) }} {{ Str::plural('order', $item['orders']) }} · {{ $number($item['qty']) }} qty</span>
                                                            </div>
                                                        @endforeach
                                                        <div class="flex justify-between gap-2" style="padding-top: 6px; border-top: 1px solid var(--pb-line2); font-weight: 700"><span>Total upsold</span><span>{{ $peso($product['upsold_value']) }} · {{ $product['sales'] ? number_format($product['upsold_value'] / $product['sales'] * 100, 1).'%' : '—' }}</span></div>
                                                        <p style="margin: 0; line-height: 1.4; {{ $muted }}">Value = the upsell's Pancake price. % of sales = value ÷ {{ $product['name'] }} sales ({{ $peso($product['sales']) }}).</p>
                                                    </div>
                                                @endif
                                                @if ($hasMonths)
                                                    <div class="flex min-w-0 flex-col gap-1.5" style="grid-column: 1 / -1">
                                                        <h3 style="{{ $h3 }}">By month</h3>
                                                        <div class="overflow-x-auto">
                                                            <table style="width: 100%; border-collapse: collapse">
                                                                <thead><tr style="text-align: right; {{ $muted }}"><th scope="col" style="text-align: left; font-weight: 600; padding: 3px 0"><span class="sr-only">Figure</span></th>@foreach ($months as $month)<th scope="col" style="font-weight: 600; padding: 3px 0 3px 10px; white-space: nowrap">{{ $monthName($month) }}</th>@endforeach</tr></thead>
                                                                <tbody>
                                                                    <tr style="text-align: right; border-top: 1px solid var(--pb-row)"><th scope="row" style="text-align: left; font-weight: 600; padding: 4px 0">Sales</th>@foreach ($byMonth as $value)<td style="{{ $miniCell }}" title="{{ $peso($value) }}">{{ $pesoShort($value) }}</td>@endforeach</tr>
                                                                    <tr style="text-align: right; border-top: 1px solid var(--pb-row)"><th scope="row" style="text-align: left; font-weight: 600; padding: 4px 0">Orders</th>@foreach ($monthlyOrders($product['name']) as $value)<td style="{{ $miniCell }}">{{ $number($value) }}</td>@endforeach</tr>
                                                                    <tr style="text-align: right; border-top: 1px solid var(--pb-row)"><th scope="row" style="text-align: left; font-weight: 600; padding: 4px 0">Rank</th>@foreach ($months as $month)<td style="{{ $miniCell }}">{{ ($place = $rankIn($month['key'], $product['name'])) ? '#'.$place : '—' }}</td>@endforeach</tr>
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                        </div>
                                                @endif
                                            </div>
                                        @endif
                                    </details>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    </div>
                    <p style="margin: 0; font-size: 12px; font-weight: 600; color: var(--pb-soft)">{{ $listed->count() }} {{ Str::plural('product', $listed->count()) }}@if (collect($products)->contains('name', $othersName)) + Others @endif · the list scrolls</p>
                    <p style="margin: 0; font-size: 12px; line-height: 1.5; {{ $muted }}">Click a product for its breakdown. Upsell = a product new to the customer, added to an order with one they've bought before, valued at its Pancake price (Shecom leaves TSD add-ons out of gross, so much of it is on top of gross). For a product, Upsell % = what was upsold on its orders ÷ its sales; under Others, the share of the item's own value that came as an upsell.</p>
                </div>
            </section>

            <section id="cra-performance" aria-labelledby="cra-performance-title" style="{{ $glass }}">
                <header class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2" style="{{ $head }}">
                    <h2 id="cra-performance-title" style="{{ $h2 }}">CRA performance, year to date</h2>
                    @if ($craPerformance)
                        <p style="margin: 0; font-size: 13px; {{ $muted }}">Team: <strong style="color: {{ $text }}">{{ $peso($craPerformance['gross']) }}</strong> gross · <strong style="color: {{ $text }}">{{ $number($craPerformance['high_aov']) }}</strong> High AOV · <strong style="color: {{ $text }}">{{ $number($craPerformance['vip']) }}</strong> VIP</p>
                    @endif
                </header>

                @if (! $craPerformance)
                    <p role="status" style="{{ $note }}; padding: 16px">These figures appear after the next scheduled refresh.</p>
                @else
                    @php
                        $craParts = collect($craPerformance['rows'])->filter(fn (array $row) => $row['gross'] > 0)->values()
                            ->map(fn (array $row, int $index) => [$row['user']->displayName(), $row['gross'], $palette[$index % count($palette)], $peso($row['gross'])])->all();
                    @endphp
                    @if ($craParts && $craPerformance['gross'] > 0)
                        <div class="flex flex-wrap items-center gap-4" style="padding: 14px 16px 0">
                            {!! $donut($craParts, $millions($craPerformance['gross']), 'team gross', "Donut: each CRA's share of team gross sales") !!}
                            <ul class="grid gap-x-4 gap-y-1.5" style="flex: 1 1 260px; margin: 0; padding: 0; list-style: none; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); font-size: 12px">
                                @foreach ($craParts as [$name, $value, $color])
                                    <li class="flex items-center gap-1.5"><span aria-hidden="true" style="{{ $swatch }}; flex: none; background: {{ $color }}"></span><span style="flex: 1">{{ $name }}</span><strong>{{ number_format($value / $craPerformance['gross'] * 100, 1) }}%</strong></li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); padding: 16px">
                        @foreach ($craPerformance['rows'] as $row)
                            @php
                                $craFigures = array_map(fn (array $month) => $craMonths[$month['key']][$row['user']->id] ?? ['gross' => 0.0, 'orders' => 0, 'aov' => null, 'capacity' => null, 'ni' => null], $months);
                                $craValues = array_column($craFigures, 'gross');
                                // By default against her first month with sales in the range (a CRA who started later has none before).
                                $craFirst = collect($craValues)->search(fn (float $value) => $value > 0);
                                $craBase = $craFirst !== false && $craFirst < $monthCount - 1 ? $craFirst : null;
                                $craPeak = max(1, ...$craValues);
                                $craY = fn (float $value) => round($value / $craPeak * 88, 2);
                                $craX = fn (int $index) => round(($index + .5) / $monthCount * 100, 2);
                                $craPoints = collect($craValues)->map(fn (float $value, int $index) => $craX($index).','.(100 - $craY($value)))->join(' ');
                            @endphp
                            <article data-cra-card class="flex flex-col gap-3.5" style="padding: 16px; {{ $cell }}; border-radius: 14px">
                                <div class="flex items-center gap-3">
                                    <x-avatar :user="$row['user']" style="width: 64px; height: 64px; font-size: 20px; font-weight: 700; color: var(--pb-text); background: {{ $crdTint }}; border: 2px solid var(--pb-hero-line)" />
                                    <div class="min-w-0">
                                        <p style="margin: 0; font-size: 12px; font-weight: 600; color: {{ $accent }}">Rank {{ $row['rank'] }}</p>
                                        <h3 style="margin: 1px 0 0; font-size: 16px; font-weight: 700">{{ $row['user']->displayName() }}</h3>
                                        @if ($row['user']->exclusive_from)
                                            <p style="margin: 2px 0 0; font-size: 12px; {{ $muted }}">Exclusive leads @if ($exclusive) · <a href="#exclusive" style="{{ $link }}">see the section</a>@endif</p>
                                        @endif
                                    </div>
                                </div>

                                <div>
                                    <p style="margin: 0; font-size: 12px; font-weight: 600; {{ $muted }}">Gross sales</p>
                                    <p style="margin: 2px 0 8px; font-size: 26px; font-weight: 700">{{ $peso($row['gross']) }}</p>
                                    <div aria-hidden="true" style="height: 6px; border-radius: 999px; background: var(--pb-track)">
                                        <div style="width: {{ round($row['share'] * 100, 1) }}%; height: 6px; border-radius: 999px; background: {{ $accent }}"></div>
                                    </div>
                                    <p style="margin: 5px 0 0; font-size: 12px; {{ $muted }}">{{ number_format($row['share'] * 100, 1) }}% of team gross</p>
                                </div>

                                @if (array_sum($craValues) > 0)
                                    {{-- Each month's dot opens its gross; pointing at one swaps the table below to that month vs the one before. --}}
                                    <div>
                                        <div style="position: relative; height: 96px; margin: 18px 6px 0">
                                            <svg viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true" style="position: absolute; inset: 0; width: 100%; height: 100%; overflow: visible">
                                                <path d="M{{ $craX(0) }},100 L{{ str_replace(' ', ' L', $craPoints) }} L{{ $craX($monthCount - 1) }},100 Z" style="fill: {{ $accent }}; opacity: .14"></path>
                                                <polyline points="{{ $craPoints }}" vector-effect="non-scaling-stroke" style="fill: none; stroke: {{ $accent }}; stroke-width: 2.5; stroke-linejoin: round; stroke-linecap: round"></polyline>
                                            </svg>
                                            @foreach ($craFigures as $index => $figures)
                                                @php
                                                    $isOpen = $isRunning && $index === $monthCount - 1;
                                                    $before = $index > 0 ? $craFigures[$index - 1] : null;
                                                    $dotChange = $index === 0 ? 'First month picked' : ($before['gross'] > 0 ? 'vs '.$months[$index - 1]['label'].' '.$chip($isOpen ? $paced($figures['gross']) : $figures['gross'], $before['gross'])['text'].($isOpen ? ', at its pace' : '') : 'No sales in '.$months[$index - 1]['label']);
                                                    $left = $craX($index);
                                                    $tipShift = $left < 25 ? '0' : ($left > 75 ? '-100%' : '-50%');
                                                @endphp
                                                <button type="button" data-cra-dot="{{ $index }}" class="pb-dot" style="left: {{ $left }}%; bottom: {{ $craY($figures['gross']) }}%" aria-label="{{ $monthName($months[$index]) }}: gross {{ $peso($figures['gross']) }}, {{ $number($figures['orders']) }} orders">
                                                    <span aria-hidden="true" class="pb-dot-mark" style="background: {{ $isOpen ? 'var(--pb-solid)' : $accent }}; border-color: {{ $accent }}"></span>
                                                    <span aria-hidden="true" class="pb-dot-tip" style="left: {{ $left < 25 ? '-4px' : ($left > 75 ? 'calc(100% + 4px)' : '50%') }}; transform: translateX({{ $tipShift }})">
                                                        <strong style="display: block; font-size: 12px">{{ $monthName($months[$index]) }} {{ \Carbon\CarbonImmutable::parse($months[$index]['key'].'-01')->format('Y') }}</strong>
                                                        <span style="display: block; margin-top: 1px; font-size: 15px; font-weight: 700">{{ $figures['gross'] > 0 ? $peso($figures['gross']) : 'No sales' }}</span>
                                                        @if ($figures['gross'] > 0)
                                                            <span style="display: block; margin-top: 2px; font-size: 11px; opacity: .8">{{ $number($figures['orders']) }} {{ Str::plural('order', $figures['orders']) }} · AOV {{ $peso($figures['aov']) }}</span>
                                                        @endif
                                                        <span style="display: block; font-size: 11px; opacity: .8">{{ $dotChange }}</span>
                                                    </span>
                                                </button>
                                            @endforeach
                                        </div>
                                        <div aria-hidden="true" style="display: grid; grid-template-columns: repeat({{ $monthCount }}, minmax(0, 1fr)); padding-top: 4px; border-top: 1px solid var(--pb-line2)">
                                            @foreach ($months as $index => $month)
                                                <span data-cra-axis="{{ $index }}" style="overflow: hidden; text-align: center; font-size: 10px; {{ $muted }}">{{ $monthCount > 8 && $index % 2 && $index !== $monthCount - 1 ? '' : $month['label'] }}</span>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                <div style="padding-top: 12px; border-top: 1px solid var(--pb-line)">
                                    <div data-cra-compare="default">
                                        @include('presentation-board._cra-compare', ['cur' => $monthCount - 1, 'base' => $craBase, 'craRow' => $row, 'hint' => $craBase === null ? 'No earlier month with sales' : ($craBase > 0 ? 'vs '.$months[$craBase]['label'].', the first month with sales' : 'last month vs first month picked')])
                                    </div>
                                    @foreach ($craFigures as $index => $figures)
                                        <div data-cra-compare="{{ $index }}" hidden>
                                            @include('presentation-board._cra-compare', ['cur' => $index, 'base' => $index > 0 ? $index - 1 : null, 'craRow' => $row, 'hint' => 'the month you point at vs the one before'])
                                        </div>
                                    @endforeach
                                </div>
                            </article>
                        @endforeach
                    </div>

                    <div class="grid gap-x-6 gap-y-2" style="grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); padding: 14px 16px; border-top: 1px solid var(--pb-line)">
                        <p style="{{ $note }}"><strong style="color: {{ $text }}">NI:</strong> the Daily I.S. CRA card's Net Income. Shows only when November or later is in the months picked.</p>
                        <p style="{{ $note }}"><strong style="color: {{ $text }}">Capacity a day:</strong> customers answered ÷ days they answered anyone (tracker contact dates + Pancake engagements).</p>
                        <p style="{{ $note }}"><strong style="color: {{ $text }}">High AOV and VIP:</strong> customers assigned to the CRA now, from all their orders. Doesn't change with the months picked. The trend line is each month's gross.</p>
                    </div>
                @endif
            </section>

            @foreach ($exclusive as $exclusiveCra)
                @include('presentation-board._exclusive', ['exclusiveCra' => $exclusiveCra])
            @endforeach

            <section id="customers" aria-labelledby="customers-title" style="{{ $glass }}">
                @php
                    $leadSeries = [
                        ['New customers', $added, $col('added'), 'added', true],
                        ['Due to reorder · FSD', $fsd, $col('fsd_leads'), 'fsd_leads', false],
                        ['Due to reorder · CRD', $crd, $col('crd_leads'), 'crd_leads', false],
                    ];
                    $leadPeak = max(1, ...$col('added'), ...$col('fsd_leads'), ...$col('crd_leads'));
                    $leadStep = $leadPeak > 4000 ? 2000 : ($leadPeak > 2000 ? 1000 : ($leadPeak > 400 ? 200 : 50));
                    $leadTop = max($leadStep, (int) ceil($leadPeak / $leadStep) * $leadStep);
                    // Lines in a stretched SVG; dots, numbers and labels are HTML so they stay their size at any width.
                    $leadHeight = 240;
                    $lx = fn (int $index) => round(($index + .5) / $monthCount * 100, 2);
                    $ly = fn (float $value) => round($value / $leadTop * 100, 2);
                    // Each labelled month's values, highest first, pushed apart so no two labels overlap.
                    $leadLabels = [];
                    foreach ($months as $index => $month) {
                        if (! ($everyValue || $index === 0 || $index === $monthCount - 1)) {
                            continue;
                        }
                        $placed = [];
                        foreach (collect($leadSeries)->map(fn (array $line) => ['value' => $line[2][$index], 'color' => $line[1]])->sortByDesc('value') as $label) {
                            if ($placed !== [] && $compact(end($placed)['value']) === $compact($label['value'])) {
                                continue;
                            }
                            $top = $leadHeight * (1 - $label['value'] / $leadTop);
                            $placed[] = [...$label, 'top' => $placed === [] ? $top : max($top, end($placed)['top'] + 16)];
                        }
                        // Keep the lowest label inside the chart, then push the ones above it up if they now overlap.
                        for ($at = count($placed) - 1; $at >= 0; $at--) {
                            $placed[$at]['top'] = round(min($placed[$at]['top'], $at === count($placed) - 1 ? $leadHeight - 9 : $placed[$at + 1]['top'] - 16), 1);
                        }
                        $leadLabels[$index] = $placed;
                    }
                @endphp
                <header class="flex flex-wrap items-center justify-between gap-2" style="{{ $head }}">
                    <h2 id="customers-title" style="{{ $h2 }}">Leads and customers</h2>
                    <span id="board-read-leads" data-read-default="1 customer = 1 lead per month" style="font-size: 13px; {{ $muted }}">1 customer = 1 lead per month</span>
                </header>
                <div class="flex flex-col gap-2" style="padding: 16px">
                    <div class="flex flex-wrap gap-x-5 gap-y-1" style="font-size: 13px">
                        @foreach ([['New customers', 'added', 'added_waiting', $added], ['Due to reorder · FSD', 'fsd_leads', 'fsd_waiting', $fsd], ['Due to reorder · CRD', 'crd_leads', 'crd_waiting', $crd]] as [$name, $metric, $wait, $color])
                            <span class="flex flex-wrap items-baseline gap-x-2">
                                <a href="{{ $listUrl($metric) }}" class="flex items-center gap-1.5" style="font-size: 15px; font-weight: 700; {{ $link }}"><span aria-hidden="true" style="width: 14px; height: 3px; border-radius: 2px; background: {{ $color }}"></span>{{ $name }} {{ $number($totals[$metric]) }}</a>
                                @if ($totals[$wait])
                                    <a href="{{ $listUrl($wait) }}" style="{{ $muted }}; {{ $link }}">+{{ $number($totals[$wait]) }} waiting for page check</a>
                                @endif
                            </span>
                        @endforeach
                    </div>
                    <div class="flex min-w-0 gap-2">
                        <div aria-hidden="true" style="position: relative; width: 44px; flex: none; height: {{ $leadHeight }}px; font-size: 12px; text-align: right; {{ $muted }}">
                            @for ($step = 0; $step <= $leadTop; $step += $leadStep)
                                <span style="position: absolute; right: 0; bottom: {{ $ly($step) }}%; transform: translateY(50%); white-space: nowrap">{{ $compact($step) }}</span>
                            @endfor
                        </div>
                        <div class="min-w-0 flex-1">
                            <div data-chart style="position: relative; height: {{ $leadHeight }}px; border-bottom: 1px solid var(--pb-axis)">
                                @for ($step = $leadStep; $step <= $leadTop; $step += $leadStep)
                                    <span aria-hidden="true" style="position: absolute; left: 0; right: 0; bottom: {{ $ly($step) }}%; height: 1px; background: var(--pb-grid)"></span>
                                @endfor
                                <svg role="img" aria-label="Line chart: new customers and customers due to reorder, FSD and CRD, by month; the numbers are in Month by month" viewBox="0 0 100 100" preserveAspectRatio="none" style="position: absolute; inset: 0; width: 100%; height: 100%; overflow: visible">
                                    @foreach ($leadSeries as $seriesIndex => [$name, $color, $values])
                                        @php
                                            $points = collect($values)->map(fn ($value, int $index) => $lx($index).','.(100 - $ly($value)))->join(' ');
                                        @endphp
                                        @if ($seriesIndex === 0 && $monthCount > 1)
                                            <path d="M{{ $lx(0) }},100 L{{ str_replace(' ', ' L', $points) }} L{{ $lx($monthCount - 1) }},100 Z" style="fill: {{ $color }}; opacity: .08"></path>
                                        @endif
                                        <polyline points="{{ $points }}" vector-effect="non-scaling-stroke" style="fill: none; stroke: {{ $color }}; stroke-width: 2.5; stroke-linejoin: round; stroke-linecap: round"></polyline>
                                    @endforeach
                                </svg>
                                @foreach ($leadSeries as $seriesIndex => [$name, $color, $values])
                                    @foreach ($values as $index => $value)
                                        <span aria-hidden="true" style="position: absolute; left: {{ $lx($index) }}%; bottom: {{ $ly($value) }}%; width: 9px; height: 9px; margin: 0 0 -4.5px -4.5px; border-radius: 50%; background: {{ $isRunning && $index === $monthCount - 1 ? 'var(--pb-solid)' : $color }}; border: 2px solid {{ $color }}; box-sizing: border-box"></span>
                                    @endforeach
                                @endforeach
                                @foreach ($leadLabels as $index => $labels)
                                    @foreach ($labels as $label)
                                        <span aria-hidden="true" style="position: absolute; left: {{ $lx($index) }}%; top: {{ $label['top'] }}px; transform: translate({{ $index === $monthCount - 1 && $monthCount > 1 ? 'calc(-100% - 9px)' : '9px' }}, -50%); padding: 0 3px; border-radius: 4px; background: var(--pb-solid); font-size: 12px; font-weight: 700; line-height: 15px; white-space: nowrap; color: {{ $label['color'] }}">{{ $compact($label['value']) }}</span>
                                    @endforeach
                                @endforeach
                                <div class="flex" style="position: absolute; inset: 0">
                                    @foreach ($months as $month)
                                        @php
                                            $read = $month['label'].': new customers '.$number($month['added']).' · due FSD '.$number($month['fsd_leads']).' · due CRD '.$number($month['crd_leads']).' · waiting '.$number($month['fsd_waiting'] + $month['crd_waiting']);
                                        @endphp
                                        <span data-point="{{ $month['key'] }}" data-read="{{ $read }}" data-read-target="board-read-leads" title="{{ $read }}" class="flex-1" style="background: transparent; border-radius: 6px"></span>
                                    @endforeach
                                </div>
                            </div>
                            <div aria-hidden="true" style="{{ $monthGrid }}; padding-top: 4px; font-size: 12px; font-weight: 600; {{ $muted }}">
                                @foreach ($months as $month)
                                    <span class="text-center">{{ $monthName($month) }}</span>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    <p style="{{ $note }}">New customers are counted when they first buy; due to reorder when their supply runs out, so the lines differ. Each month's customers open from Month by month.</p>
                </div>
            </section>

            <section id="details" style="{{ $glass }}">
                <header class="flex flex-wrap items-center justify-between gap-2" style="{{ $head }}">
                    <h2 style="{{ $h2 }}">Daily and weekly, FSD and CRD</h2>
                    <nav aria-label="Period" class="flex w-fit gap-0.5" style="border-radius: 8px; background: var(--pb-chip); padding: 2px">
                        @foreach (\App\Services\PresentationBoard::PERIODS as $value => $label)
                            <a href="{{ route('presentation-board.index', [...$linkQuery, 'period' => $value]) }}#details" @if ($period === $value) aria-current="page" @endif
                               class="flex items-center" style="min-height: 40px; border-radius: 6px; padding: 0 14px; font-size: 14px; font-weight: 600; {{ $period === $value ? 'background: var(--pb-btn); color: var(--pb-btn-text)' : 'color: var(--pb-text)' }}">{{ $label }}</a>
                        @endforeach
                    </nav>
                </header>
                <div class="flex flex-wrap">
                    @foreach ($teams as $team)
                        @php
                            $peak = max(1, ...array_map(fn (array $row) => max($row[$team['leads']] + $row[$team['wait']], $row[$team['bought']]), $periods));
                            $readId = 'board-read-'.$team['id'];
                            $defaultRead = 'Point at the chart for its numbers';
                            $columns = 'display: grid; grid-template-columns: 1.3fr repeat('.($team['added'] ? 6 : 5).', 1fr); gap: 4px; min-width: 470px; padding: 6px; text-align: right';
                            $periodCount = count($periods);
                            $px = fn (int $index) => $periodCount === 1 ? 300 : round($index / ($periodCount - 1) * 600, 1);
                            $py = fn (float $value) => round(150 - $value / $peak * 140, 1);
                            $duePoints = collect($periods)->map(fn (array $row, int $index) => $px($index).','.$py($row[$team['leads']] + $row[$team['wait']]))->join(' ');
                            $boughtPoints = collect($periods)->map(fn (array $row, int $index) => $px($index).','.$py($row[$team['bought']]))->join(' ');
                            $areaOf = fn (string $points) => 'M'.$px(0).',150 L'.str_replace(' ', ' L', $points).' L'.$px($periodCount - 1).',150 Z';
                        @endphp
                        <div class="flex min-w-0 flex-col gap-2.5" style="flex: 1 1 520px; padding: 14px 16px">
                            <div class="flex flex-wrap items-center justify-between gap-2" style="font-size: 13px">
                                <h3 style="margin: 0; font-size: 15px; font-weight: 700">{{ $team['name'] }}</h3>
                                <span class="flex flex-wrap gap-3" style="{{ $muted }}">
                                    <span class="flex items-center gap-1.5"><span aria-hidden="true" style="{{ $swatch }}; background: {{ $team['tint'] }}"></span>Leads, with waiting</span>
                                    <span class="flex items-center gap-1.5"><span aria-hidden="true" style="width: 14px; height: 3px; border-radius: 2px; background: {{ $team['color'] }}"></span>{{ $team['boughtName'] }}</span>
                                </span>
                            </div>
                            <div id="{{ $readId }}" data-read-default="{{ $defaultRead }}" style="min-height: 18px; font-size: 13px; font-weight: 600">{{ $defaultRead }}</div>
                            <div data-chart style="position: relative; {{ $axis }}">
                                <svg viewBox="0 0 600 150" role="img" aria-label="Area chart: {{ $team['name'] }}, leads and {{ strtolower($team['boughtName']) }} per {{ ['daily' => 'day', 'weekly' => 'week', 'monthly' => 'month'][$period] }}; the numbers are in the table below" preserveAspectRatio="none" style="display: block; width: 100%; height: 140px">
                                    <path d="{{ $areaOf($duePoints) }}" style="fill: {{ $team['tint'] }}"></path>
                                    <path d="{{ $areaOf($boughtPoints) }}" style="fill: {{ $team['color'] }}; opacity: .35"></path>
                                    <polyline points="{{ $boughtPoints }}" vector-effect="non-scaling-stroke" style="fill: none; stroke: {{ $team['color'] }}; stroke-width: 2.5; stroke-linejoin: round"></polyline>
                                </svg>
                                <div class="flex" style="position: absolute; inset: 0">
                                    @foreach ($periods as $index => $row)
                                        @php
                                            $read = $row['label'].': '.$number($row[$team['leads']]).' leads · '.$number($row[$team['wait']]).' waiting · '.$number($row[$team['bought']]).' '.strtolower($team['boughtName']).' · '.$number($row[$team['lost']]).' churned';
                                        @endphp
                                        <span data-point="{{ $team['id'] }}-{{ $index }}" data-read="{{ $read }}" data-read-target="{{ $readId }}" title="{{ $read }}" class="min-w-0 flex-1" style="background: transparent"></span>
                                    @endforeach
                                </div>
                            </div>
                            <div aria-hidden="true" class="flex justify-between" style="font-size: 12px; font-weight: 600; {{ $muted }}"><span>{{ $periods[0]['label'] }}</span><span>{{ $periods[array_key_last($periods)]['label'] }}</span></div>
                            <div class="overflow-auto" style="max-height: 300px; font-size: 13px; border-radius: 8px">
                                <div style="{{ $columns }}; position: sticky; top: 0; background: var(--pb-solid); font-weight: 600; color: var(--pb-soft)">
                                    <span class="text-left">{{ ['daily' => 'Day', 'weekly' => 'Week', 'monthly' => 'Month'][$period] }}</span><span>Leads</span>
                                    @if ($team['added'])<span title="New customers (was Acquired leads)">New</span>@endif
                                    <span title="Customers the rate is out of: {{ $team['boughtName'] }} ÷ Out of = Rate">Out of</span><span>{{ $team['boughtName'] }}</span><span>Rate</span><span>Churn %</span>
                                </div>
                                @foreach ($periods as $row)
                                    <div style="{{ $columns }}; {{ $rowLine }}">
                                        <span class="text-left" style="font-weight: 600">{{ $row['label'] }}</span><span>{{ $number($row[$team['leads']]) }}@if ($row[$team['wait']])<br><span style="font-size: 11px; {{ $muted }}">+{{ $number($row[$team['wait']]) }}</span>@endif</span>
                                        @if ($team['added'])<span>{{ $number($row['added']) }}</span>@endif
                                        <span>{{ $number($row[$team['base']]) }}</span><span>{{ $number($row[$team['bought']]) }}</span><span>{{ $percent($row[$team['rate']]) }}</span><span>{{ $percent($row[$team['churn']]) }}<span style="{{ $small }}">{{ $number($row[$team['lost']]) }} of {{ $number($row[$team['due']]) }}</span></span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

            <section id="churn" aria-labelledby="churn-title" style="{{ $glass }}">
                @php
                    $churnPeak = max(1, ...array_map(fn (array $month) => max($month['added'] + $month['added_waiting'], $month['all_lost'] + $month['all_reactivation']), $months));
                @endphp
                <header class="flex flex-wrap items-center justify-between gap-2" style="{{ $head }}">
                    <h2 id="churn-title" style="{{ $h2 }}">New customers vs churn</h2>
                    <ul class="flex flex-wrap gap-3.5" style="margin: 0; padding: 0; list-style: none; font-size: 13px">
                        <li class="flex items-center gap-1.5"><span aria-hidden="true" style="{{ $swatch }}; background: {{ $added }}"></span>New customers {{ $number($totals['added']) }}</li>
                        @if ($totals['added_waiting'])
                            <li class="flex items-center gap-1.5"><span aria-hidden="true" style="{{ $swatch }}; background: {{ $addedTint }}"></span>New customers, waiting {{ $number($totals['added_waiting']) }}</li>
                        @endif
                        <li class="flex items-center gap-1.5"><span aria-hidden="true" style="{{ $swatch }}; background: {{ $lost }}"></span>Churned {{ $number($totals['all_lost']) }}</li>
                        <li class="flex items-center gap-1.5"><span aria-hidden="true" style="{{ $swatch }}; background: {{ $reactivation }}"></span>For reactivation {{ $number($totals['all_reactivation']) }}</li>
                        <li style="font-weight: 700">Net {{ $signed($totals['net']) }}</li>
                    </ul>
                </header>
                <div class="flex flex-col gap-4" style="padding: 16px">
                    <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(260px, 1fr))">
                        @foreach (['all', 'fsd', 'crd'] as $list)
                            @php
                                $figures = $totals['churn_lists'][$list];
                                $churnShare = $figures['due'] ? min(1, $figures['lost'] / $figures['due']) : 0;
                            @endphp
                            <div class="flex items-center gap-3.5" style="{{ $cell }}; border-radius: 14px; padding: 12px 14px">
                                <svg viewBox="0 0 42 42" role="img" aria-label="{{ $figures['name'] }} churn {{ $percent($figures['rate']) }}" style="display: block; width: 76px; height: 76px; flex: none">
                                    <circle cx="21" cy="21" r="15.9155" style="fill: none; stroke: var(--pb-track); stroke-width: 5"></circle>
                                    <circle cx="21" cy="21" r="15.9155" style="fill: none; stroke: {{ $lost }}; stroke-width: 5; stroke-linecap: round; stroke-dasharray: {{ round($churnShare * 100, 2) }} {{ round(100 - $churnShare * 100, 2) }}; stroke-dashoffset: 25"></circle>
                                    <text x="21" y="23.5" style="fill: var(--pb-text); font-size: 7px; font-weight: 700; text-anchor: middle">{{ $percent($figures['rate']) }}</text>
                                </svg>
                                <div class="flex flex-col gap-1">
                                    <h3 style="margin: 0; font-size: 14px; font-weight: 600">{{ $figures['name'] }} churn</h3>
                                    <span style="font-size: 13px; {{ $muted }}"><a href="{{ $cardUrl('lost', $list) }}" style="{{ $link }}">{{ $number($figures['lost']) }}</a> of <a href="{{ $cardUrl('due', $list) }}" style="{{ $link }}">{{ $number($figures['due']) }}</a> due · <a href="{{ $cardUrl('back', $list) }}" style="{{ $link }}">{{ $number($figures['back']) }}</a> came back · <a href="{{ $cardUrl('reactivation', $list) }}" style="{{ $link }}">{{ $number($figures['reactivation']) }}</a> for reactivation</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="overflow-x-auto">
                        <div data-chart class="flex gap-1" style="min-width: {{ max(560, 90 + $monthCount * 70) }}px; font-size: 13px">
                            <div style="width: 100px; flex: none; font-weight: 600; {{ $muted }}">
                                <div style="height: 222px"></div>
                                @foreach (['Month', 'New customers', 'Churned', 'Reactivation', 'Net'] as $label)
                                    <div style="height: 24px; line-height: 24px">{{ $label }}</div>
                                @endforeach
                                @foreach (['Churn %', 'FSD %', 'CRD %'] as $label)
                                    <div style="height: 38px; line-height: 19px">{{ $label }}</div>
                                @endforeach
                            </div>
                            @foreach ($months as $month)
                                <div data-point="{{ $month['key'] }}" title="{{ $month['label'] }}: new customers {{ $number($month['added']) }}, plus {{ $number($month['added_waiting']) }} waiting, {{ $number($month['all_lost']) }} churned and {{ $number($month['all_reactivation']) }} for reactivation of {{ $number($month['all_due']) }} due" class="min-w-0 flex-1 text-center" style="background: transparent; border-radius: 6px">
                                    <span class="flex flex-col items-center justify-end gap-0.5" style="height: 120px"><span style="display: block; width: 100%; max-width: 24px; height: {{ round($month['added_waiting'] / $churnPeak * 100) }}%; border-radius: 4px 4px 0 0; background: {{ $addedTint }}"></span><span style="display: block; width: 100%; max-width: 24px; height: {{ round($month['added'] / $churnPeak * 100) }}%; background: {{ $added }}"></span></span>
                                    <span style="display: block; height: 2px; background: var(--pb-axis)"></span>
                                    <span class="flex flex-col items-center justify-start gap-0.5" style="height: 100px"><span style="display: block; width: 100%; max-width: 24px; height: {{ round($month['all_lost'] / $churnPeak * 100) }}%; background: {{ $lost }}"></span><span style="display: block; width: 100%; max-width: 24px; height: {{ round($month['all_reactivation'] / $churnPeak * 100) }}%; border-radius: 0 0 4px 4px; background: {{ $reactivation }}"></span></span>
                                    <span style="display: block; height: 24px; line-height: 24px; font-weight: 700">{{ $month['label'] }}</span>
                                    <a href="{{ $listUrl('added', $month['key']) }}" style="display: block; height: 24px; line-height: 24px; {{ $link }}">{{ $number($month['added'] + $month['added_waiting']) }}</a>
                                    <a href="{{ $churnUrl('lost', 'all', $month['key']) }}" style="display: block; height: 24px; line-height: 24px; {{ $link }}">{{ $number($month['all_lost']) }}</a>
                                    <a href="{{ $churnUrl('reactivation', 'all', $month['key']) }}" style="display: block; height: 24px; line-height: 24px; {{ $link }}">{{ $number($month['all_reactivation']) }}{{ $star($month) }}</a>
                                    <span style="display: block; height: 24px; line-height: 24px; font-weight: 700; color: {{ $month['net'] < 0 ? $lost : $added }}">{{ $signed($month['net']) }}</span>
                                    <span style="display: block; height: 38px; line-height: 19px; font-weight: 700">{{ $percent($month['churn_rate']) }}{{ $star($month) }}<span style="{{ $small }}">{{ $number($month['all_lost']) }} of {{ $number($month['all_due']) }}</span></span>
                                    <a href="{{ $churnUrl('lost', 'fsd', $month['key']) }}" style="display: block; height: 38px; line-height: 19px; {{ $link }}">{{ $percent($month['fsd_churn_rate']) }}{{ $star($month) }}<span style="{{ $small }}">{{ $number($month['fsd_lost']) }} of {{ $number($month['fsd_due']) }}</span></a>
                                    <a href="{{ $churnUrl('lost', 'crd', $month['key']) }}" style="display: block; height: 38px; line-height: 19px; {{ $link }}">{{ $percent($month['crd_churn_rate']) }}{{ $star($month) }}<span style="{{ $small }}">{{ $number($month['crd_lost']) }} of {{ $number($month['crd_due']) }}</span></a>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    <div class="flex flex-col gap-1">
                        <p style="{{ $note }}">Above the line: new customers. Below: churned and for reactivation. Monthly numbers open exactly those customers. Card lists cover the whole range and show each customer once, so they can be shorter.</p>
                        <p style="{{ $note }}"><strong style="color: {{ $text }}">Churned</strong>: a CRA reached them, 30 days passed, no order. <strong style="color: {{ $text }}">For reactivation</strong>: everyone else due, mostly not reached yet, not in the rate.</p>
                        @if ($hasPartial)
                            <p style="{{ $note }}">* Partial: tracker leads start in June. Rates above use {{ $totals['churn_label'] }} only.</p>
                        @endif
                        @if ($totals['added_waiting'])
                            <p style="{{ $note }}">New customers and Net rows include customers waiting for their page check.</p>
                        @endif
                    </div>
                </div>
            </section>

            <details id="counted" style="{{ $glass }}">
                <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2" style="{{ $head }}">
                    <h2 style="{{ $h2 }}">How each number is counted</h2>
                    <span style="font-size: 13px; {{ $muted }}">Click to show or hide</span>
                </summary>
                <dl class="grid gap-x-4 gap-y-2" style="margin: 0; padding: 12px 16px 16px; grid-template-columns: minmax(110px, 190px) minmax(0, 1fr); font-size: 14px">
                    @foreach ([
                        'Due to reorder (was FSD / CRD leads)' => '1 customer = 1 lead per month, by est. out-of-stock date. CRD-catered products and listed pages only. FSD = no CRA has handled them yet; CRD = a CRA sold to them before. In both teams: counts under CRD. Jan–Mar months are approximate (quantity from order items).',
                        'Waiting' => 'Page not confirmed yet. Not in the totals until the backfill checks it.',
                        'New customers (was Acquired leads)' => 'First delivery on record (from June 2025), from FSD. Counted once, in the month delivered.',
                        'Change chip' => 'The last month picked against the first month picked (Month by month has the same change for every figure): counts in %, rates in points (pts). A month still running is compared at its full-month pace (so far × days in the month ÷ days so far); rates never are.',
                        'Churned' => 'A CRA reached them (contact date, status or attempt), 30 days passed, no order since, not marked bought or Reserve.',
                        'Churn rate' => 'Churned ÷ due, for FSD, CRD and overall. Range totals, not an average of months. Overall counts a customer on both lists once.',
                        'For reactivation' => 'Everyone else due with no order in time. Not in the rate.',
                        'Retain, repeat, conversion' => "Same as each month's Dashboard tiles. Conversion from October only.",
                        'Conversion by lead type' => 'FSD leads = FSD leads who bought ÷ FSD leads given; CRD leads the same for CRD (together the Segmentation rate). Overall also counts broadcast buyers over engagements, as the Dashboard.',
                        'Sales' => "Every CRA's gross, as Conversion Breakdown. Shecom's amount per order, Pancake's until synced.",
                        'Segmentation vs Broadcast' => "The same sales split by each order's CRD - SEGMENTATION / CRD - BROADCAST tag (the CVR Tracker's Type of Order wins). Orders before the tags started aren't split. AOV = gross ÷ confirmed orders of that type.",
                        'Top products' => "The same orders split by each item's Pancake price × qty (SRP when an item has none). Unlisted items go under Others.",
                        'Upsell' => "A product the customer hasn't bought before (in any earlier Pancake order, canceled left out, named as on the new order's day) on an order with one they have. It's filed under the biggest of those as upsold on its orders, at its Pancake price (Shecom's sales leave TSD add-ons out, so its value can be on top of gross); Upsell % = upsold ÷ the product's sales. Top products has its own dates; they follow the months until you pick some.",
                        'Exclusive leads' => "As the Exclusive Leads Dashboard: the CRA's month list (leads still theirs), its targets, Repeat + Retained bought from it, confirmed orders, and sales split Exclusive (her exclusive leads, assigned or tagged in POS) vs Normal.",
                    ] as $name => $how)
                        <dt style="font-weight: 700">{{ $name }}</dt>
                        <dd style="margin: 0; {{ $muted }}">{{ $how }}</dd>
                    @endforeach
                </dl>
            </details>
        @endif
    </div>
</x-layouts.app>
