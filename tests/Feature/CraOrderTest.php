<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\LeadGenerator;
use App\Support\PancakeAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CraOrderTest extends TestCase
{
    use RefreshDatabase;

    private function cra(string $name, ?int $order): User
    {
        return User::create([
            'email' => str_replace(' ', '', strtolower($name)).'@example.com', 'display_name' => $name, 'pancake_name' => 'CRD '.$name,
            'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true, 'cra_order' => $order,
        ]);
    }

    public function test_cras_are_listed_in_the_set_order_and_anyone_else_after_oldest_first(): void
    {
        $this->cra('Joanna Rose', 3);
        $this->cra('New Hire', null);
        $this->cra('Regina', 1);
        $this->cra('Rose-An', 5);
        $this->cra('Lhea', 4);
        $this->cra('July Ann', 2);
        $this->cra('Newer Hire', null);

        $expected = ['Regina', 'July Ann', 'Joanna Rose', 'Lhea', 'Rose-An', 'New Hire', 'Newer Hire'];

        $this->assertSame($expected, LeadGenerator::cras()->map->displayName()->all());
        $this->assertSame($expected, PancakeAccounts::craUsers()->map->displayName()->all());
    }

    public function test_the_migration_sets_the_order_by_display_name(): void
    {
        $users = collect(['Lhea', 'Regina', 'Rose-An', 'July Ann', 'Joanna Rose'])->map(fn (string $name) => $this->cra($name, null));
        $migration = require database_path('migrations/2026_10_16_100000_add_cra_order_to_users_table.php');
        Schema::table('users', fn ($table) => $table->dropColumn('cra_order'));

        $migration->up();

        $this->assertSame(['Regina', 'July Ann', 'Joanna Rose', 'Lhea', 'Rose-An'], LeadGenerator::cras()->map->displayName()->all());
        $this->assertCount(5, $users);
    }
}
