<?php

namespace Database\Factories;

use App\Models\DutyLogin;
use App\Models\Role;
use App\Models\User;
use App\Support\WorkingDate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DutyLogin>
 */
class DutyLoginFactory extends Factory
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
            'date' => WorkingDate::realToday()->toDateString(),
            'logged_in_at' => now(),
        ];
    }

    public function on(string $date): static
    {
        return $this->state(['date' => $date, 'logged_in_at' => $date.' 08:00:00']);
    }
}
