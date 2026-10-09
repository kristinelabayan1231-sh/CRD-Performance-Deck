<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ProductConsumptionSeeder;
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

    public function test_srp_is_saved_and_sets_the_cltv(): void
    {
        $this->actingAs($this->owner)->post('/settings/product-consumption', ['name' => 'CanPro', 'srp' => '499'])->assertSessionHasNoErrors();
        $product = Product::firstWhere('name', 'CanPro');
        $this->assertSame(14970.0, $product->cltv());

        $this->actingAs($this->owner)->get('/settings/product-consumption')->assertSee('₱14,970');

        $this->actingAs($this->owner)->patch("/settings/product-consumption/{$product->id}", ['name' => 'CanPro', 'srp' => '-1'])
            ->assertSessionHasErrorsIn("product{$product->id}", ['srp']);
        $this->actingAs($this->owner)->patch("/settings/product-consumption/{$product->id}", ['name' => 'CanPro', 'srp' => ''])->assertSessionHasNoErrors();
        $this->assertNull($product->fresh()->cltv());
    }

    public function test_a_supervisor_can_set_only_the_srp(): void
    {
        $product = Product::create(['name' => 'CanPro', 'keywords' => 'Can Pro', 'consumption_days' => 10]);
        $supervisor = User::create(['email' => 'sup@gmail.com', 'role_id' => Role::where('slug', Role::CRA_SUPERVISOR)->value('id'), 'is_active' => true]);

        $this->actingAs($supervisor)->get('/settings/product-consumption')
            ->assertOk()->assertSee(route('settings.product-consumption.srp', $product))->assertDontSee('Add product');

        $this->actingAs($supervisor)->patch(route('settings.product-consumption.srp', $product), ['srp' => '499', 'name' => 'Renamed'])
            ->assertSessionHasNoErrors();
        $this->assertSame(['CanPro', '499.00'], [$product->fresh()->name, $product->fresh()->srp]);

        $this->actingAs($supervisor)->patch("/settings/product-consumption/{$product->id}", ['name' => 'Renamed'])->assertForbidden();
    }

    public function test_consumption_days_are_saved_and_must_be_a_sensible_number(): void
    {
        $this->actingAs($this->owner)->post('/settings/product-consumption', ['name' => 'CanPro', 'consumption_days' => '10'])->assertSessionHasNoErrors();
        $product = Product::firstWhere('name', 'CanPro');
        $this->assertSame(10, $product->consumption_days);

        $this->actingAs($this->owner)->patch("/settings/product-consumption/{$product->id}", ['name' => 'CanPro', 'consumption_days' => ''])->assertSessionHasNoErrors();
        $this->assertNull($product->fresh()->consumption_days);

        $this->actingAs($this->owner)->post('/settings/product-consumption', ['name' => 'Bad', 'consumption_days' => '0'])->assertSessionHasErrors('consumption_days');
    }

    public function test_seeder_adds_products_and_keeps_existing_keywords(): void
    {
        Product::create(['name' => 'Scar Cream', 'keywords' => 'Scar Gel', 'consumption_days' => 15]);

        $this->seed(ProductConsumptionSeeder::class);
        $this->seed(ProductConsumptionSeeder::class);

        $this->assertSame(count(ProductConsumptionSeeder::PRODUCTS), Product::count());
        $scar = Product::firstWhere('name', 'Scar Cream');
        $this->assertSame(10, $scar->consumption_days);
        $this->assertSame('Scar Gel', $scar->keywords);
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
