<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\Spiel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Spiel>
 */
class SpielFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => fn () => User::create([
                'email' => fake()->unique()->safeEmail(), 'display_name' => fake()->firstName(),
                'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true,
            ])->id,
            'title' => fake()->words(2, true),
            'body' => 'Hi {name}! Malapit na pong maubos ang inyong {product}.',
            'position' => 0,
        ];
    }
}
