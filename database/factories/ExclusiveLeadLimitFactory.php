<?php

namespace Database\Factories;

use App\Models\ExclusiveLeadLimit;
use App\Support\WorkingDate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExclusiveLeadLimit>
 */
class ExclusiveLeadLimitFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'effective_from' => WorkingDate::realToday()->toDateString(),
            'daily_leads' => 250,
            'month_target' => 7000,
            'orders_per_day' => 54,
        ];
    }
}
