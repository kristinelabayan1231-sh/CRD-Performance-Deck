<?php

namespace Database\Factories;

use App\Models\OrderStatusChange;
use App\Models\PancakeOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderStatusChange>
 */
class OrderStatusChangeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pancake_order_id' => (string) fake()->unique()->numberBetween(1000000, 1999999),
            'from_status' => PancakeOrder::CANCELED,
            'to_status' => 2,
        ];
    }
}
