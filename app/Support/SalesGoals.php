<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;

/**
 * Sales goals from Settings → Sales Goals: each CRA's daily base, CRD's
 * monthly target and CRD's monthly net income goal. Sales are Conversion Breakdown's gross sales (orders tagged
 * CRD - BROADCAST + CRD - SEGMENTATION).
 */
class SalesGoals
{
    public const CRA_DAILY = 'sales_goal.cra_daily';

    public const CRD_MONTHLY = 'sales_goal.crd_monthly';

    public const NET_INCOME_MONTHLY = 'sales_goal.net_income_monthly';

    public const RETAINED_RATE = 'sales_goal.retained_rate';

    public const REPEAT_RATE = 'sales_goal.repeat_rate';

    public static function craDaily(): float
    {
        return (float) Setting::value(self::CRA_DAILY, config('segmentation.sales_goals.cra_daily'));
    }

    /**
     * The CRA's own daily goal when management set one, else the general CRA daily goal.
     */
    public static function dailyFor(User $cra, ?float $general = null): float
    {
        return $cra->daily_sales_goal !== null ? (float) $cra->daily_sales_goal : ($general ?? self::craDaily());
    }

    public static function crdMonthly(): float
    {
        return (float) Setting::value(self::CRD_MONTHLY, config('segmentation.sales_goals.crd_monthly'));
    }

    /**
     * CRD's net income goal for the month, or null when none is set.
     */
    public static function netIncomeMonthly(): ?float
    {
        $value = Setting::value(self::NET_INCOME_MONTHLY);

        return $value === null ? null : (float) $value;
    }

    /**
     * CRD's monthly Retained customers goal, as a percent of all the leads (30 = 30%).
     */
    public static function retainedRate(): float
    {
        return (float) Setting::value(self::RETAINED_RATE, config('segmentation.sales_goals.retained_rate'));
    }

    /**
     * CRD's monthly Repeat customers goal, as a percent of all the leads (30 = 30%).
     */
    public static function repeatRate(): float
    {
        return (float) Setting::value(self::REPEAT_RATE, config('segmentation.sales_goals.repeat_rate'));
    }

    /**
     * Customers needed out of $leads to reach $goalPercent (30% of 3,218 = 966), rounded up.
     */
    public static function customersForGoal(int $leads, float $goalPercent): int
    {
        return (int) ceil(round($leads * $goalPercent / 100, 6));
    }

    /**
     * Share of $goal reached by $sales (1.0 = goal hit; can go over), or null without a goal.
     */
    public static function progress(float $sales, float $goal): ?float
    {
        return $goal > 0 ? $sales / $goal : null;
    }
}
