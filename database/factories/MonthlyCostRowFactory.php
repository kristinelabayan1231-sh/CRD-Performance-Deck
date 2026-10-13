<?php

namespace Database\Factories;

use App\Models\MonthlyCostRow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonthlyCostRow>
 */
class MonthlyCostRowFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'position' => 'Customer Retention Associate',
            'name' => fake()->name(),
            'pay_type' => MonthlyCostRow::FIXED,
            'salary' => 19500,
        ];
    }

    public function fixed(float $salary): static
    {
        return $this->state(['pay_type' => MonthlyCostRow::FIXED, 'salary' => $salary]);
    }

    public function vacant(): static
    {
        return $this->state(['name' => null]);
    }
}
