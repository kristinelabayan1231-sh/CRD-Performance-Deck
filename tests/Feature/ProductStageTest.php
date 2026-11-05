<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductStageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_product_must_be_testing_or_existing(): void
    {
        $owner = User::create(['email' => 'kristinelabayan1231@gmail.com', 'role_id' => Role::superAdmin()->id, 'is_active' => true]);

        $this->actingAs($owner)->post(route('settings.product-consumption.store'), ['name' => 'Thyro Ease'])->assertSessionHasErrors('is_testing');
        $this->actingAs($owner)->post(route('settings.product-consumption.store'), ['name' => 'Thyro Ease', 'is_testing' => '1'])->assertSessionHasNoErrors();

        $this->assertTrue(Product::firstWhere('name', 'Thyro Ease')->is_testing);
    }

    public function test_a_supervisor_marks_the_testing_products(): void
    {
        $supervisor = User::create(['email' => 'sup@example.com', 'role_id' => Role::firstWhere('slug', Role::CRA_SUPERVISOR)->id, 'is_active' => true]);
        $rose = Product::create(['name' => 'Rose Soap', 'is_testing' => true]);
        $cystora = Product::create(['name' => 'Cystora 2.0']);
        $sinuxyl = Product::create(['name' => 'Sinuxyl']);

        $this->actingAs($supervisor)->get(route('settings.product-stage.index'))
            ->assertOk()->assertSeeInOrder(['Testing', 'Rose Soap', 'Existing', 'Cystora 2.0', 'Sinuxyl']);

        $this->actingAs($supervisor)->put(route('settings.product-stage.update'), ['testing' => [$cystora->id]])->assertSessionHasNoErrors();

        $this->assertSame([false, true, false], [$rose->fresh()->is_testing, $cystora->fresh()->is_testing, $sinuxyl->fresh()->is_testing]);
    }

    public function test_a_cra_cannot_change_them(): void
    {
        $cra = User::create(['email' => 'cra@example.com', 'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true]);

        $this->actingAs($cra)->put(route('settings.product-stage.update'), ['testing' => []])->assertForbidden();
    }
}
