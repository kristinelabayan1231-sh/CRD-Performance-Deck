<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Customer's Feedback as several choices. `feedbacks` lists every one picked, the main one first; `feedback` is that
 * main one, which the deck's rules read (Converted, CVR Tracker, Top feedback). PURCHASED is always the main one when
 * picked. Writing `feedback` directly (imports, a status that implies it) makes it the main one and keeps the others.
 */
trait HasFeedbacks
{
    public const FEEDBACK_FIRST = 'purchased';

    protected static function bootHasFeedbacks(): void
    {
        static::saving(function (Model $model) {
            if ($model->isDirty('feedbacks')) {
                $model->feedbacks = self::orderFeedbacks($model->feedbacks ?? []);
            } elseif ($model->isDirty('feedback')) {
                $others = array_diff(self::storedFeedbacks($model->getOriginal('feedbacks'), $model->getOriginal('feedback')), [$model->feedback]);
                $model->feedbacks = $model->feedback === null ? null : self::orderFeedbacks([$model->feedback, ...$others]);
            } else {
                return;
            }

            $model->feedback = $model->feedbacks[0] ?? null;
        });
    }

    public function initializeHasFeedbacks(): void
    {
        $this->mergeCasts(['feedbacks' => 'array']);
    }

    /**
     * Every Customer's Feedback choice picked, the main one first.
     *
     * @return list<string>
     */
    public function feedbackKeys(): array
    {
        return self::storedFeedbacks($this->feedbacks, $this->feedback);
    }

    /**
     * Set the choices from a form: those already picked keep their place, new ones go after them.
     *
     * @param  array<int, string>  $keys
     */
    public function pickFeedbacks(array $keys): void
    {
        $current = $this->feedbackKeys();
        $this->feedbacks = [...array_intersect($current, $keys), ...array_diff($keys, $current)];
    }

    /**
     * Add one choice (a status that implies it) as the main one, keeping the others.
     */
    public function addFeedback(string $key): void
    {
        $this->feedbacks = [$key, ...array_diff($this->feedbackKeys(), [$key])];
    }

    /**
     * The stored list, or for a row saved before choices could be several, just its `feedback`.
     *
     * @param  array<int, string>|string|null  $feedbacks
     * @return list<string>
     */
    public static function storedFeedbacks(array|string|null $feedbacks, ?string $feedback): array
    {
        $feedbacks = is_string($feedbacks) ? json_decode($feedbacks, true) : $feedbacks;

        return $feedbacks ? array_values($feedbacks) : ($feedback === null ? [] : [$feedback]);
    }

    /**
     * Unique, blanks dropped, PURCHASED first.
     *
     * @param  array<int, ?string>  $keys
     * @return list<string>|null
     */
    private static function orderFeedbacks(array $keys): ?array
    {
        $keys = array_values(array_unique(array_filter(array_map('strval', $keys), fn (string $key) => $key !== '')));

        if (in_array(self::FEEDBACK_FIRST, $keys, true)) {
            $keys = [self::FEEDBACK_FIRST, ...array_diff($keys, [self::FEEDBACK_FIRST])];
        }

        return $keys ? array_values($keys) : null;
    }
}
