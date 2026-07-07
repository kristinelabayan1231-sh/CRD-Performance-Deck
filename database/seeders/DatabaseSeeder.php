<?php

namespace Database\Seeders;

use App\Models\AllowedEmail;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        AllowedEmail::updateOrCreate(
            ['email' => 'kristinelabayan1231@gmail.com'],
            ['is_admin' => true],
        );
    }
}
