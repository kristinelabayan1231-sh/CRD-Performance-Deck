<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PcReportTest extends TestCase
{
    use RefreshDatabase;

    private User $regina;

    private User $julyAnn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->regina = $this->user('Regina', Role::CRA);
        $this->julyAnn = $this->user('July Ann', Role::CRA);
    }

    private function user(string $name, string $role): User
    {
        return User::create([
            'email' => str_replace(' ', '', strtolower($name)).'@example.com', 'display_name' => $name,
            'role_id' => Role::firstWhere('slug', $role)->id, 'is_active' => true,
        ]);
    }

    public function test_supervisor_sees_every_cra_set_and_can_edit_the_creation_update_time(): void
    {
        $supervisor = $this->user('Sup', Role::CRA_SUPERVISOR);

        $this->actingAs($supervisor)->get(route('pc-report.index', ['product' => 'sinuxyl']))
            ->assertOk()
            ->assertSeeInOrder(['Sinuxyl (Regina)', 'PC - Sinuxyl Nasal Spray', 'PC - Sinuxyl Nasal Care', 'PC - Sinuxyl Official Page', 'TOTAL'])
            ->assertSee('Sinuxyl (July Ann)')
            ->assertSee('data-pc-window', false)
            ->assertSee('data-pc-split', false);
    }

    public function test_supervisor_can_narrow_to_one_cra(): void
    {
        $supervisor = $this->user('Sup', Role::CRA_SUPERVISOR);

        $this->actingAs($supervisor)->get(route('pc-report.index', ['cra' => $this->julyAnn->id]))
            ->assertOk()
            ->assertSee('Sinuxyl (July Ann)')
            ->assertDontSee('Sinuxyl (Regina)');
    }

    public function test_cra_sees_only_their_own_set_and_cannot_edit_the_creation_update_time(): void
    {
        $this->actingAs($this->regina)->get(route('pc-report.index', ['cra' => $this->julyAnn->id]))
            ->assertOk()
            ->assertSee('Sinuxyl (Regina)')
            ->assertDontSee('Sinuxyl (July Ann)')
            ->assertDontSee('data-pc-window', false)
            ->assertDontSee('data-pc-split', false)
            ->assertSee('set by your CRA Supervisor');
    }

    public function test_product_picks_the_pages_in_its_group(): void
    {
        $this->actingAs($this->regina)->get(route('pc-report.index', ['product' => 'clearsight']))
            ->assertOk()
            ->assertSee('ClearSight (Regina)')
            ->assertSee('PC - Clearsight Eye Drop')
            ->assertDontSee('PC - Sinuxyl Nasal Spray');
    }

    public function test_user_without_pc_report_permission_is_refused(): void
    {
        $this->actingAs($this->user('Plain', Role::USER))->get(route('pc-report.index'))->assertForbidden();
    }
}
