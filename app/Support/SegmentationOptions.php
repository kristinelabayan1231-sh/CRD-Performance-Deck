<?php

namespace App\Support;

use App\Models\Lead;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Segmentation Tracker dropdown choices edited in Settings → Segmentation Tracker.
 * A saved list replaces config('segmentation.<list>'), so every page reading the
 * config sees the edited labels and colours.
 */
class SegmentationOptions
{
    public const KEY_PREFIX = 'segmentation_options.';

    /** Contact attempts a cold lead gets (Settings → Segmentation Tracker), replacing config('segmentation.cold_attempts'). */
    public const COLD_ATTEMPTS_KEY = 'segmentation_cold_attempts';

    /**
     * Put every saved list, and the cold lead attempts, into config. Called on boot; skipped quietly before the settings table exists.
     */
    public static function apply(): void
    {
        Setting::where('key', 'like', self::KEY_PREFIX.'%')->pluck('value', 'key')
            ->each(function (?string $json, string $key) {
                $options = json_decode((string) $json, true);
                $list = Str::after($key, self::KEY_PREFIX);

                if (is_array($options) && $options && isset(config('segmentation.editable_lists')[$list])) {
                    config(["segmentation.{$list}" => $options]);
                }
            });

        if ($attempts = (int) Setting::value(self::COLD_ATTEMPTS_KEY)) {
            config(['segmentation.cold_attempts' => $attempts]);
        }
    }

    /**
     * Save how many contact attempts a cold lead gets and use it right away.
     */
    public static function saveColdAttempts(int $attempts, User $by): void
    {
        Setting::put(self::COLD_ATTEMPTS_KEY, (string) $attempts, $by);
        config(['segmentation.cold_attempts' => $attempts]);
    }

    /**
     * Save $list's choices in order and use them right away.
     *
     * @param  array<string, array{0: string, 1: string}>  $options
     */
    public static function save(string $list, array $options, User $by): void
    {
        Setting::put(self::KEY_PREFIX.$list, json_encode($options), $by);
        config(["segmentation.{$list}" => $options]);
    }

    /**
     * Keys of $list that leads hold, with how many leads hold each.
     *
     * @return array<string, int>
     */
    public static function usage(string $list): array
    {
        $column = config("segmentation.editable_lists.{$list}.column");

        // Customer's Feedback can hold several choices: each one ticked counts, not just the main one.
        if ($list === 'feedback') {
            return Lead::whereNotNull('feedback')->toBase()->get(['feedback', 'feedbacks'])
                ->flatMap(fn (object $lead) => Lead::storedFeedbacks($lead->feedbacks, $lead->feedback))
                ->countBy()->all();
        }

        return Lead::whereNotNull($column)->groupBy($column)->selectRaw("{$column} as option_key, count(*) as total")
            ->pluck('total', 'option_key')->map(fn ($total) => (int) $total)->all();
    }

    /**
     * A new key for $label that isn't already in $taken.
     *
     * @param  array<int, string>  $taken
     */
    public static function newKey(string $label, array $taken): string
    {
        $base = Str::limit(Str::slug($label, '_'), 40, '') ?: 'option';
        $key = $base;

        for ($n = 2; in_array($key, $taken, true); $n++) {
            $key = "{$base}_{$n}";
        }

        return $key;
    }
}
