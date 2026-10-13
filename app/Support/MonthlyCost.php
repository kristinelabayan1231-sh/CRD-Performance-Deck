<?php

namespace App\Support;

use App\Models\MonthlyCostRow;
use App\Models\Setting;
use Illuminate\Support\Collection;

/**
 * CRD - MC: what the Customer Retention Dept. costs a month.
 *
 * Customer Retention (TOTAL SALARY OF CRD) = every row's monthly salary + the shared salaries
 * (Sales Manager, VP of Internal Affairs, CEO: base salary × CRD's share). 13th Month = Customer
 * Retention ÷ 12; SIL = Customer Retention × 12 ÷ 313; Gov. Benefits = (PHIC + HDMF + SSS) ×
 * the people (rows with a name). Total = the four added up before rounding, as in the sheet, so the
 * parts shown (rounded to centavos) can be a centavo off the total.
 */
class MonthlyCost
{
    /** Settings key prefix; each setting is stored as monthly_cost.<key>. */
    public const PREFIX = 'monthly_cost.';

    /** @var array<string, float>|null */
    private ?array $settings = null;

    /**
     * Every setting, saved value or its default.
     *
     * @return array<string, float>
     */
    public function settings(): array
    {
        if ($this->settings === null) {
            $saved = Setting::where('key', 'like', self::PREFIX.'%')->pluck('value', 'key');
            $this->settings = collect(config('monthly_cost.defaults'))
                ->map(fn ($default, string $key) => (float) ($saved[self::PREFIX.$key] ?? $default))->all();
        }

        return $this->settings;
    }

    public function setting(string $key): float
    {
        return $this->settings()[$key];
    }

    public function dailyRate(string $payType): float
    {
        return $this->settings()['rate_'.$payType] ?? 0.0;
    }

    public function workingDays(): float
    {
        return $this->setting('working_days');
    }

    public function govBenefitsPerPerson(): float
    {
        return round($this->setting('phic') + $this->setting('hdmf') + $this->setting('sss'), 2);
    }

    /**
     * The shared salaries: base salary × CRD's share.
     *
     * @return list<array{label: string, salary: float, share: float, amount: float}>
     */
    public function shared(): array
    {
        return array_map(fn (array $row) => [
            'label' => $row[0],
            'salary' => $this->setting($row[1].'_salary'),
            'share' => $this->setting($row[1].'_share'),
            'amount' => round($this->setting($row[1].'_salary') * $this->setting($row[1].'_share') / 100, 2),
        ], [['Sales Manager', 'sales_manager'], ['VP of Internal Affairs', 'vp'], ['CEO', 'ceo']]);
    }

    /**
     * @param  Collection<int, MonthlyCostRow>  $rows
     * @return array{team: float, shared: float, customer_retention: float, thirteenth_month: float, sil: float, gov_benefits: float, people: int, total: float}
     */
    public function totals(Collection $rows): array
    {
        $team = round($rows->sum(fn (MonthlyCostRow $row) => $row->monthlySalary()), 2);
        $shared = round(collect($this->shared())->sum('amount'), 2);
        $retention = round($team + $shared, 2);
        $people = $rows->filter(fn (MonthlyCostRow $row) => $row->isFilled())->count();
        $gov = round($this->govBenefitsPerPerson() * $people, 2);
        // In centavos, so a half centavo rounds up as in the sheet (281,979.30 ÷ 12 = 23,498.28, not .27).
        $cents = (int) round($retention * 100);
        $thirteenth = $cents / 12;
        $sil = $cents * 12 / config('monthly_cost.sil_days');

        return [
            'team' => $team,
            'shared' => $shared,
            'customer_retention' => $retention,
            'thirteenth_month' => round($thirteenth) / 100,
            'sil' => round($sil) / 100,
            'gov_benefits' => $gov,
            'people' => $people,
            'total' => round($cents + $thirteenth + $sil + $gov * 100) / 100,
        ];
    }
}
