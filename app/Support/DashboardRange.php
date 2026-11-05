<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * The dashboard's dates: a month (Jan–Dec, month to date by default) or a From–To range.
 * Days after today are left out, so the current month runs from the 1st to today.
 */
class DashboardRange
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public CarbonImmutable $month,
        public bool $custom,
    ) {}

    /**
     * @param  array{month?: ?string, from?: ?string, to?: ?string}  $filters  validated query
     */
    public static function fromFilters(array $filters, CarbonImmutable $today): self
    {
        if (filled($filters['from'] ?? null) || filled($filters['to'] ?? null)) {
            $from = CarbonImmutable::parse($filters['from'] ?? $today->startOfMonth()->toDateString());
            $to = CarbonImmutable::parse($filters['to'] ?? $today->toDateString())->min($today);

            return new self($from, $to->max($from), $to->startOfMonth(), true);
        }

        $month = CarbonImmutable::parse(($filters['month'] ?? $today->format('Y-m')).'-01')->min($today->startOfMonth());

        return new self($month, $month->endOfMonth()->startOfDay()->min($today), $month, false);
    }

    /**
     * Number of days in the range.
     */
    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /**
     * Whether the range is a whole month so far (the 1st to today, or a past month in full).
     */
    public function isMonth(): bool
    {
        return $this->from->equalTo($this->from->startOfMonth()) && $this->from->isSameMonth($this->to)
            && ($this->to->equalTo($this->to->endOfMonth()->startOfDay()) || ! $this->custom);
    }

    public function label(): string
    {
        if ($this->from->equalTo($this->to)) {
            return $this->from->format('D, M j');
        }

        $label = $this->from->format('M j').'–'.($this->from->isSameMonth($this->to) ? $this->to->format('j') : $this->to->format('M j'));

        return $this->from->isSameYear(WorkingDate::realToday()) ? $label : $label.', '.$this->to->format('Y');
    }

    /**
     * The same number of days just before, for comparisons.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function previous(): array
    {
        return [$this->from->subDays($this->days()), $this->from->subDay()];
    }
}
