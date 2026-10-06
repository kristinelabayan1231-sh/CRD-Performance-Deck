<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductConsumptionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create(['email' => 'kristinelabayan1231@gmail.com', 'role_id' => Role::superAdmin()->id, 'is_active' => true]);
    }

    private function userWith(array $permissions): User
    {
        $role = Role::create(['slug' => 'r'.count(Role::all()), 'name' => 'Role '.count(Role::all()), 'permissions' => $permissions]);

        return User::create(['email' => $role->slug.'@gmail.com', 'role_id' => $role->id, 'is_active' => true]);
    }

    public function test_settings_redirects_to_product_consumption(): void
    {
        $this->actingAs($this->owner)->get('/settings')->assertRedirect('/settings/product-consumption');
        $this->actingAs($this->owner)->get('/settings/product-consumption')
            ->assertOk()->assertSee('Product Consumption')->assertSee('Add product');
    }

    public function test_product_can_be_created(): void
    {
        $this->actingAs($this->owner)
            ->post('/settings/product-consumption', ['name' => '  Sinuxyl ', 'keywords' => 'Sinuvex'])
            ->assertSessionHasNoErrors()->assertSessionHas('status');

        $product = Product::firstWhere('name', 'Sinuxyl');
        $this->assertSame('Sinuvex', $product->keywords);
        $this->assertSame($this->owner->id, $product->created_by);

        $this->actingAs($this->owner)->get('/settings/product-consumption')->assertSee('Sinuxyl')->assertSee('Sinuvex');
    }

    public function test_product_name_is_required(): void
    {
        $this->actingAs($this->owner)->post('/settings/product-consumption', ['name' => '  '])->assertSessionHasErrors('name');
        $this->assertSame(0, Product::count());
    }

    public function test_product_names_are_unique(): void
    {
        Product::create(['name' => 'Audicure']);

        $this->actingAs($this->owner)
            ->post('/settings/product-consumption', ['name' => 'Audicure'])
            ->assertSessionHasErrors('name');
    }

    public function test_product_can_be_updated_and_deleted(): void
    {
        $product = Product::create(['name' => 'Pterylief']);

        $this->actingAs($this->owner)
            ->patch("/settings/product-consumption/{$product->id}", ['name' => 'Pterylief Eye Drops', 'keywords' => 'Ptery Clear'])
            ->assertSessionHasNoErrors();
        $this->assertSame(['Pterylief Eye Drops', 'Ptery Clear'], [$product->fresh()->name, $product->fresh()->keywords]);

        // Saving with its own name is not a duplicate.
        $this->actingAs($this->owner)
            ->patch("/settings/product-consumption/{$product->id}", ['name' => 'Pterylief Eye Drops'])
            ->assertSessionHasNoErrors();

        // Invalid edits go to the product's own error bag.
        $this->actingAs($this->owner)
            ->patch("/settings/product-consumption/{$product->id}", ['name' => ''])
            ->assertSessionHasErrorsIn("product{$product->id}", 'name');

        $this->actingAs($this->owner)->delete("/settings/product-consumption/{$product->id}");
        $this->assertModelMissing($product);
    }

    public function test_permissions_control_access(): void
    {
        $product = Product::create(['name' => 'Canpro']);

        $nobody = User::create(['email' => 'plain@gmail.com', 'role_id' => Role::defaultUser()->id, 'is_active' => true]);
        $this->actingAs($nobody)->get('/settings/product-consumption')->assertForbidden();
        $this->actingAs($nobody)->get('/dashboard')->assertDontSee('/settings/product-consumption', false);

        $viewer = $this->userWith(['product_consumption.view']);
        $this->actingAs($viewer)->get('/settings/product-consumption')->assertOk()->assertSee('Canpro')->assertDontSee('Add product');
        $this->actingAs($viewer)->post('/settings/product-consumption', ['name' => 'X'])->assertForbidden();
        $this->actingAs($viewer)->delete("/settings/product-consumption/{$product->id}")->assertForbidden();

        $editor = $this->userWith(['product_consumption.view', 'product_consumption.manage']);
        $this->actingAs($editor)->post('/settings/product-consumption', ['name' => 'Y'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('products', ['name' => 'Y']);
    }

    public function test_settings_permissions_appear_in_role_creation(): void
    {
        $this->actingAs($this->owner)->get('/user-access/roles')
            ->assertSee('Settings · Product Consumption')
            ->assertSee('Add, edit and delete products');
    }
}
