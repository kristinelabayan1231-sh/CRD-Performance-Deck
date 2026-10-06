<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\ProductCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_name_is_a_whole_keyword_ignoring_case_and_spaces(): void
    {
        Product::create(['name' => 'Pterygium']);
        Product::create(['name' => 'Clearsight']);
        Product::create(['name' => 'AudiCure']);
        Product::create(['name' => 'Sinuxyl', 'keywords' => 'Sinuvex, Sinusitis Spray']);

        $catalog = new ProductCatalog;
        $match = fn (string $raw) => $catalog->match($raw)?->name;

        $this->assertSame('Pterygium', $match('Pterygium Drops'));
        $this->assertSame('Pterygium', $match('Pterygium Eye Repair Drops'));
        $this->assertNull($match('Ptery Clear'));            // not the whole keyword
        $this->assertSame('Clearsight', $match('Clear Sight 3.0'));
        $this->assertSame('AudiCure', $match('Audicure'));
        $this->assertSame('Sinuxyl', $match('Sinuxyl Steam Pack'));
        $this->assertSame('Sinuxyl', $match('Sinuvex Nasal Spray')); // extra keyword
        $this->assertNull($match('HearWell'));
    }

    public function test_saving_products_regroups_existing_leads(): void
    {
        $owner = User::create(['email' => 'kristinelabayan1231@gmail.com', 'role_id' => Role::superAdmin()->id, 'is_active' => true]);
        $lead = Lead::create([
            'order_id' => '1', 'customer_name' => 'A', 'phone_number' => '9171', 'product_name' => 'Pterygium Eye Drops',
            'product_raw' => 'Pterygium Eye Drops', 'qty' => 1, 'delivered_date' => '2026-09-01', 'consumption_days' => 15,
            'est_out_of_stock_date' => '2026-09-15', 'lead_type' => Lead::TYPE_NEW,
        ]);

        $this->actingAs($owner)->post('/settings/product-consumption', ['name' => 'Pterygium'])->assertSessionHasNoErrors();
        $this->assertSame(['Pterygium', 'Pterygium Eye Drops'], [$lead->fresh()->product_name, $lead->fresh()->product_raw]);

        // Deleting the product puts the original name back.
        $this->actingAs($owner)->delete('/settings/product-consumption/'.Product::first()->id);
        $this->assertSame('Pterygium Eye Drops', $lead->fresh()->product_name);
    }

    public function test_keywords_are_saved_tidied(): void
    {
        $owner = User::create(['email' => 'kristinelabayan1231@gmail.com', 'role_id' => Role::superAdmin()->id, 'is_active' => true]);

        $this->actingAs($owner)->post('/settings/product-consumption', ['name' => 'Clearsight', 'keywords' => ' Clear Sight , ,Clearsite,Clear Sight']);

        $this->assertSame('Clear Sight, Clearsite', Product::first()->keywords);
    }
}
