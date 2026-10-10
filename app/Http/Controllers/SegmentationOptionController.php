<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Support\SegmentationOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SegmentationOptionController extends Controller
{
    public function index(): View
    {
        $lists = collect(config('segmentation.editable_lists'))->map(fn (array $list, string $name) => [
            ...$list,
            'options' => config("segmentation.{$name}"),
            'usage' => SegmentationOptions::usage($name),
        ]);

        return view('settings.segmentation-options', [
            'lists' => $lists,
            'colors' => config('segmentation.option_colors'),
            'lastChange' => Setting::where('key', 'like', SegmentationOptions::KEY_PREFIX.'%')->with('editor')->latest('updated_at')->first(),
        ]);
    }

    /**
     * Save one list: rename or recolour choices, remove unused ones and add new ones at the end.
     */
    public function update(Request $request, string $list): RedirectResponse
    {
        $settings = config("segmentation.editable_lists.{$list}");
        abort_unless($settings, 404);

        $current = config("segmentation.{$list}");
        $colors = config('segmentation.option_colors');

        $errorBag = "options_{$list}";
        $data = $request->validateWithBag($errorBag, [
            'options' => ['required', 'array'],
            'options.*.key' => ['nullable', 'string', Rule::in(array_map('strval', array_keys($current)))],
            'options.*.label' => ['nullable', 'string', 'max:80'],
            'options.*.color' => ['nullable', Rule::in(array_keys($colors))],
            'options.*.remove' => ['nullable', 'boolean'],
        ], [], ['options.*.label' => 'choice name']);

        $usage = SegmentationOptions::usage($list);
        $saved = [];
        $fail = fn (string $message) => throw ValidationException::withMessages(['options' => $message])->errorBag($errorBag);

        foreach ($data['options'] as $row) {
            $key = $row['key'] ?? null;
            $label = trim((string) ($row['label'] ?? ''));

            if ($key === null && $label === '') {
                continue;
            }

            if ($key !== null && ($row['remove'] ?? false)) {
                continue;
            }

            if ($label === '') {
                $fail('Every choice needs a name.');
            }

            $key ??= SegmentationOptions::newKey($label, [...array_map('strval', array_keys($current)), ...array_keys($saved)]);
            $classes = $colors[$row['color'] ?? ''] ?? (is_array($current[$key] ?? null) ? $current[$key][1] : reset($colors));
            $saved[$key] = [$label, $classes];
        }

        // Removed (or left out) choices must be unlocked and unused.
        foreach (array_diff_key($current, $saved) as $key => [$label]) {
            if (in_array((string) $key, $settings['locked'], true)) {
                $fail("“{$label}” can't be removed: the deck's rules use it. Rename it instead.");
            }
            if ($usage[$key] ?? 0) {
                $fail("“{$label}” can't be removed: {$usage[$key]} ".str('lead')->plural($usage[$key]).' still use it.');
            }
        }

        $labels = array_map(fn (array $option) => mb_strtolower($option[0]), $saved);
        if (count($labels) !== count(array_unique($labels))) {
            $fail('Two choices have the same name.');
        }
        if (! $saved) {
            $fail('Keep at least one choice.');
        }

        SegmentationOptions::save($list, $saved, $request->user());

        return to_route('settings.segmentation-options.index')->with('status', "{$settings['label']} choices saved.");
    }
}
