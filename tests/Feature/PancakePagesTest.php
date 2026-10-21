<?php

namespace Tests\Feature;

use App\Models\PancakePage;
use App\Models\Role;
use App\Models\User;
use App\Support\ProductGroups;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PancakePagesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    /** When true, the fake Pancake API refuses every token. */
    private bool $refuse = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create(['email' => 'kristinelabayan1231@gmail.com', 'role_id' => Role::superAdmin()->id, 'is_active' => true]);
        Http::fake(['pages.fm/*' => fn () => $this->refuse
            ? Http::response('Server internal error', 500)
            : Http::response(['success' => true, 'users_engagements' => []])]);
    }

    public function test_admin_adds_a_page_and_it_is_tested_with_the_token_encrypted(): void
    {
        $this->actingAs($this->owner)->post(route('settings.pancake-pages.store'), [
            'name' => 'Trusted Eye Care', 'page_id' => '119912345', 'access_token' => 'page-secret-token',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $page = PancakePage::firstWhere('page_id', '119912345');
        $this->assertSame('page-secret-token', $page->access_token);
        $this->assertNotSame('page-secret-token', DB::table('pancake_pages')->value('access_token'));
        $this->assertTrue($page->check_ok);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/pages/119912345/statistics/customer_engagements'));

        // The list shows only the token's last characters.
        $this->actingAs($this->owner)->get(route('settings.pancake-pages.index'))
            ->assertOk()
            ->assertSee('Trusted Eye Care')
            ->assertSee('••••oken')
            ->assertDontSee('page-secret-token');
    }

    public function test_blank_token_keeps_the_saved_one_and_pages_can_be_turned_off(): void
    {
        $page = PancakePage::create(['name' => 'Old name', 'page_id' => '1001', 'access_token' => 'keep-me']);

        $this->actingAs($this->owner)->patch(route('settings.pancake-pages.update', $page), [
            'name' => 'New name', 'page_id' => '1001', 'access_token' => '', 'is_active' => '0',
        ])->assertSessionHasNoErrors();

        $page->refresh();
        $this->assertSame('New name', $page->name);
        $this->assertSame('keep-me', $page->access_token);
        $this->assertFalse($page->is_active);
    }

    public function test_page_ids_must_be_numbers_and_unique(): void
    {
        PancakePage::create(['name' => 'Taken', 'page_id' => '1001', 'access_token' => 't']);

        $this->actingAs($this->owner)->post(route('settings.pancake-pages.store'), ['name' => 'A', 'page_id' => 'abc', 'access_token' => 't'])
            ->assertSessionHasErrors('page_id');
        $this->actingAs($this->owner)->post(route('settings.pancake-pages.store'), ['name' => 'B', 'page_id' => '1001', 'access_token' => 't'])
            ->assertSessionHasErrors('page_id');
    }

    public function test_a_refused_token_is_shown_after_testing(): void
    {
        $this->refuse = true;
        $page = PancakePage::create(['name' => 'Broken', 'page_id' => '1001', 'access_token' => 'expired']);

        $this->actingAs($this->owner)->post(route('settings.pancake-pages.test', $page))->assertRedirect();

        $this->assertFalse($page->fresh()->check_ok);
        $this->actingAs($this->owner)->get(route('settings.pancake-pages.index'))->assertSee('Refused');
    }

    public function test_only_users_with_the_permission_can_open_it(): void
    {
        $cra = User::create(['email' => 'cra@example.com', 'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true]);
        $page = PancakePage::create(['name' => 'P', 'page_id' => '1001', 'access_token' => 't']);

        $this->actingAs($cra)->get(route('settings.pancake-pages.index'))->assertForbidden();
        $this->actingAs($cra)->delete(route('settings.pancake-pages.destroy', $page))->assertForbidden();
        $this->assertModelExists($page);
    }

    private function pages(string ...$names): void
    {
        foreach ($names as $i => $name) {
            PancakePage::create(['name' => $name, 'page_id' => (string) (5000 + $i), 'access_token' => 't']);
        }
    }

    private function saveGroups(array $groups): TestResponse
    {
        return $this->actingAs($this->owner)->from(route('settings.pancake-pages.index'))
            ->put(route('settings.pancake-pages.groups'), ['groups' => json_encode($groups)]);
    }

    public function test_product_groups_are_saved_and_pc_report_uses_them(): void
    {
        $this->pages('Pterygium Eye Repair', 'Pterylief Eye Drops Ph', 'Trusted Eye Care');
        $this->actingAs($this->owner)->get(route('settings.pancake-pages.index'))->assertSee('Sample groups');

        $this->saveGroups([['name' => ' Pterygium ', 'pages' => ['Pterygium Eye Repair', 'Pterylief Eye Drops Ph']], ['name' => 'Eye Care', 'pages' => []]])
            ->assertRedirect(route('settings.pancake-pages.index'))
            ->assertSessionHas('status');

        $this->assertSame([
            ['slug' => 'pterygium', 'name' => 'Pterygium', 'pages' => ['Pterygium Eye Repair', 'Pterylief Eye Drops Ph'], 'added_on' => null],
            ['slug' => 'eye-care', 'name' => 'Eye Care', 'pages' => [], 'added_on' => null],
        ], ProductGroups::all());
        $this->actingAs($this->owner)->get(route('settings.pancake-pages.index'))
            ->assertDontSee('Sample groups')
            ->assertSeeInOrder(['Pterygium', 'Pterygium Eye Repair', 'Pterylief Eye Drops Ph', 'Pages not in a group', 'Trusted Eye Care']);
        $this->actingAs($this->owner)->get(route('pc-report.index', ['product' => 'pterygium']))
            ->assertOk()
            ->assertSeeInOrder(['Pterygium · 2 pages', 'Eye Care · 0 pages'])
            ->assertDontSee('Sinuxyl · 3 pages');
    }

    public function test_product_groups_need_known_pages_each_in_one_group_and_different_names(): void
    {
        $this->pages('Pterygium Eye Repair', 'Trusted Eye Care');

        $this->saveGroups([['name' => 'A', 'pages' => ['Pterygium Eye Repair']], ['name' => 'B', 'pages' => ['Pterygium Eye Repair']]])
            ->assertSessionHasErrorsIn('groups');
        $this->saveGroups([['name' => 'A', 'pages' => ['No Such Page']]])->assertSessionHasErrorsIn('groups');
        $this->saveGroups([['name' => 'Eye Care', 'pages' => []], ['name' => 'eye care', 'pages' => []]])->assertSessionHasErrorsIn('groups');
        $this->saveGroups([['name' => 'Clear Sight', 'pages' => []], ['name' => 'Clear-Sight', 'pages' => []]])->assertSessionHasErrorsIn('groups');
        $this->saveGroups([])->assertSessionHasErrorsIn('groups');

        $this->assertFalse(ProductGroups::isSaved());
    }

    public function test_only_users_with_the_permission_can_save_product_groups(): void
    {
        $cra = User::create(['email' => 'cra@example.com', 'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true]);

        $this->actingAs($cra)->put(route('settings.pancake-pages.groups'), ['groups' => json_encode([['name' => 'A', 'pages' => []]])])->assertForbidden();
        $this->assertFalse(ProductGroups::isSaved());
    }
}
