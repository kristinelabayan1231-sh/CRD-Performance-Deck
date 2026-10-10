@php($control = 'h-10 w-full rounded-lg border border-line bg-white px-3 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-200 focus:outline-none')

<x-layouts.app title="Segmentation Tracker Choices">
    @include('settings._header')

    <p class="mb-4 text-sm text-muted">The choices CRAs pick from in the Segmentation Tracker. Rename or recolour any choice; leads keep it. A choice can be removed only while no lead uses it, and choices marked <span class="font-semibold text-ink">Used by rules</span> (conversions, carry-over, hot/warm/cold) can only be renamed.</p>

    <div class="space-y-4">
        @foreach ($lists as $name => $list)
            @php($bag = $errors->getBag("options_{$name}"))
            @php($rows = [...collect($list['options'])->map(fn ($option, $key) => ['key' => (string) $key, 'label' => $option[0], 'classes' => $option[1]])->values(), ...array_fill(0, 3, ['key' => null, 'label' => '', 'classes' => null])])

            <section class="overflow-hidden rounded-xl bg-white shadow-sm" aria-labelledby="list-{{ $name }}">
                <form method="POST" action="{{ route('settings.segmentation-options.update', $name) }}">
                    @csrf
                    @method('PUT')

                    <header class="flex flex-wrap items-center justify-between gap-3 px-4 pt-4 pb-3">
                        <div>
                            <h2 id="list-{{ $name }}" class="text-base font-semibold">{{ $list['label'] }}</h2>
                            <p class="text-sm text-muted">{{ count($list['options']) }} choices. Fill a blank row to add one at the end.</p>
                        </div>
                        <button type="submit" class="h-10 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-700">Save {{ $list['label'] }}</button>
                    </header>

                    @if ($bag->any())
                        <div role="alert" class="mx-4 mb-3 rounded-lg border border-coral/60 bg-coral/10 px-4 py-3 text-sm text-coral-700">{{ $bag->first() }}</div>
                    @endif

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[720px] text-left text-sm">
                            <thead class="bg-canvas/60 text-xs tracking-wide text-muted uppercase">
                                <tr>
                                    <th class="px-4 py-3 font-semibold">Preview</th>
                                    <th class="px-4 py-3 font-semibold">Choice</th>
                                    <th class="px-4 py-3 font-semibold">Colour</th>
                                    <th class="px-4 py-3 text-right font-semibold">Leads</th>
                                    <th class="px-4 py-3 font-semibold">Remove</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                @foreach ($rows as $i => $row)
                                    @php($used = $row['key'] !== null ? ($list['usage'][$row['key']] ?? 0) : 0)
                                    @php($locked = $row['key'] !== null && in_array($row['key'], $list['locked'], true))
                                    @php($color = $bag->any() ? old("options.{$i}.color") : array_search($row['classes'], $colors, true))
                                    <tr @class(['bg-canvas/30' => $row['key'] === null])>
                                        <td class="px-4 py-3">
                                            <span data-option-preview @class(['rounded-full px-3 py-1.5 text-xs font-semibold whitespace-nowrap', $colors[$color] ?? 'bg-[#e6e6e6] text-[#3d3d3d]'])>{{ $row['label'] ?: 'New choice' }}</span>
                                        </td>
                                        <td class="px-4 py-3">
                                            @if ($row['key'] !== null)
                                                <input type="hidden" name="options[{{ $i }}][key]" value="{{ $row['key'] }}">
                                            @endif
                                            <label class="block min-w-64">
                                                <span class="sr-only">{{ $row['key'] !== null ? "Name for {$row['label']}" : 'New choice name' }}</span>
                                                <input type="text" name="options[{{ $i }}][label]" maxlength="80" @required($row['key'] !== null)
                                                       value="{{ $bag->any() ? old("options.{$i}.label") : $row['label'] }}"
                                                       placeholder="{{ $row['key'] === null ? 'Add a choice…' : '' }}" class="{{ $control }}">
                                            </label>
                                        </td>
                                        <td class="px-4 py-3">
                                            <label class="block w-40">
                                                <span class="sr-only">Colour</span>
                                                <select name="options[{{ $i }}][color]" data-option-color data-colors='@json($colors)' class="{{ $control }}">
                                                    @foreach ($colors as $colorName => $classes)
                                                        <option value="{{ $colorName }}" @selected($color === $colorName)>{{ $colorName }}</option>
                                                    @endforeach
                                                </select>
                                            </label>
                                        </td>
                                        <td class="px-4 py-3 text-right tabular-nums">{{ $row['key'] !== null ? number_format($used) : '' }}</td>
                                        <td class="px-4 py-3">
                                            @if ($row['key'] === null)
                                                <span class="text-xs text-muted">New</span>
                                            @elseif ($locked)
                                                <span class="text-xs text-muted">Used by rules</span>
                                            @elseif ($used)
                                                <span class="text-xs text-muted">In use</span>
                                            @else
                                                <label class="inline-flex items-center gap-2 text-sm">
                                                    <input type="checkbox" name="options[{{ $i }}][remove]" value="1" @checked($bag->any() && old("options.{$i}.remove"))
                                                           class="size-4 rounded border-line text-coral focus:ring-coral/40">
                                                    Remove
                                                </label>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </form>
            </section>
        @endforeach
    </div>

    @if ($lastChange?->editor)
        <p class="mt-4 text-xs text-muted">Last changed by {{ $lastChange->editor->displayName() }} on {{ $lastChange->updated_at->timezone(config('segmentation.timezone'))->format('M j, Y g:i A') }}.</p>
    @endif

    <script>
        // Live preview: the badge follows the typed name and picked colour.
        document.querySelectorAll('tr').forEach((row) => {
            const preview = row.querySelector('[data-option-preview]');
            const label = row.querySelector('input[type=text]');
            const color = row.querySelector('[data-option-color]');
            if (! preview || ! label || ! color) return;

            const base = 'rounded-full px-3 py-1.5 text-xs font-semibold whitespace-nowrap';
            const colors = JSON.parse(color.dataset.colors);
            const sync = () => {
                preview.className = `${base} ${colors[color.value] ?? ''}`;
                preview.textContent = label.value.trim() || 'New choice';
            };
            label.addEventListener('input', sync);
            color.addEventListener('change', sync);
        });
    </script>
</x-layouts.app>
