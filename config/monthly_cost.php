<?php

/*
|--------------------------------------------------------------------------
| CRD - MC (monthly cost)
|--------------------------------------------------------------------------
|
| Starting values for CRD - MC's settings; once someone saves them in the
| module, the saved values are used instead.
|
*/

return [

    // Pay types: daily-rated ones are paid their rate × working_days; fixed is typed in per row.
    'pay_types' => [
        'trainee' => 'Trainee',
        'probationary' => 'Probationary',
        'regular' => 'Regular',
        'fixed' => 'Fixed salary',
    ],

    'defaults' => [
        'rate_trainee' => 600,
        'rate_probationary' => 670,
        'rate_regular' => 750,
        'working_days' => 26,

        // Salaries CRD shares a part of: base salary × share %.
        'sales_manager_salary' => 60000,
        'sales_manager_share' => 33.33,
        'vp_salary' => 0,
        'vp_share' => 33.33,
        'ceo_salary' => 126000,
        'ceo_share' => 33.33,

        // Government benefits per person a month.
        'phic' => 622.70,
        'hdmf' => 400,
        'sss' => 1878.10,

        // Per person a month; listed for reference, not added to the total.
        'reliability_reward' => 2000,
        'rice_incentives' => 1000,
        'free_meal' => 2600,
    ],

    // SIL = Customer Retention × 12 ÷ these working days a year.
    'sil_days' => 313,

];
