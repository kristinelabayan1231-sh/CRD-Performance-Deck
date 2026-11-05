<?php

namespace Database\Factories;

use App\Models\PancakeReservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PancakeReservation>
 */
class PancakeReservationFactory extends Factory
{
    /**
     * A reservation for a customer with no order found (Qty to Type blank).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $phoneKey = '9'.fake()->numerify('#########');

        return [
            'customer_name' => fake()->name(),
            'phone_number' => '0'.$phoneKey,
            'phone_key' => $phoneKey,
            'customer_tag' => 'hot',
            'repeat_purchase' => 'reserve',
        ];
    }
}
