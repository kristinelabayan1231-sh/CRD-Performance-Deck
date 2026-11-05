<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgeBracketTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_products_age_bracket_is_saved(): void
    {
        $owner = User::create(['email' => 'kristinelabayan1231@gmail.com', 'role_id' => Role::superAdmin()->id, 'is_active' => true]);
        $clearSight = Product::create(['name' => 'Clear Sight 3.0']);
        $sinuxyl = Product::create(['name' => 'Sinuxyl', 'age_min' => 30, 'age_max' => 50]);

        $this->actingAs($owner)->get(route('settings.age-bracket.index'))
            ->assertOk()->assertSeeInOrder(['Clear Sight 3.0', 'Sinuxyl', 'Save']);

        // A blank end means no limit; a product left out of the form loses its bracket.
        $this->actingAs($owner)->put(route('settings.age-bracket.update'), ['brackets' => [$clearSight->id => ['min' => '45', 'max' => '']]])
            ->assertSessionHasNoErrors();
        $this->assertSame([45, null], [$clearSight->fresh()->age_min, $clearSight->fresh()->age_max]);
        $this->assertSame([null, null], [$sinuxyl->fresh()->age_min, $sinuxyl->fresh()->age_max]);

        $this->actingAs($owner)->put(route('settings.age-bracket.update'), ['brackets' => [$clearSight->id => ['min' => '60', 'max' => '40']]])
            ->assertSessionHasErrors("brackets.{$clearSight->id}.min");
        $this->assertSame(45, $clearSight->fresh()->age_min);
    }

    public function test_a_supervisor_sees_the_brackets_but_cannot_change_them(): void
    {
        $product = Product::create(['name' => 'Clear Sight 3.0', 'age_min' => 45]);
        $supervisor = User::create(['email' => 'sup@gmail.com', 'role_id' => Role::where('slug', Role::CRA_SUPERVISOR)->value('id'), 'is_active' => true]);

        $this->actingAs($supervisor)->get(route('settings.age-bracket.index'))->assertOk()->assertSee('Clear Sight 3.0')->assertDontSee('>Save<', false);
        $this->actingAs($supervisor)->put(route('settings.age-bracket.update'), ['brackets' => [$product->id => ['min' => '20']]])->assertForbidden();
    }
}
