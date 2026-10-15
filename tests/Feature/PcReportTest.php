<?php

namespace Tests\Feature;

use App\Models\PcReportRow;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
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

    public function test_supervisor_saves_the_creation_update_time(): void
    {
        $supervisor = $this->user('Sup', Role::CRA_SUPERVISOR);

        $this->actingAs($supervisor)->putJson(route('pc-report.rows.update'), ['cra' => $this->regina->id, 'page' => 'Clear Sight Hub', 'category' => null, 'from' => '2025-05', 'to' => '2025-06'])
            ->assertOk();

        $this->assertDatabaseHas('pc_report_rows', ['user_id' => $this->regina->id, 'page' => 'Clear Sight Hub', 'category' => '', 'from_month' => '2025-05-01 00:00:00']);
        $this->actingAs($supervisor)->get(route('pc-report.index', ['product' => 'clearsight', 'cra' => $this->regina->id]))
            ->assertOk()
            ->assertSee('May – Jun 2025');
    }

    public function test_counts_typed_for_a_day_show_on_that_day_only_and_add_up_in_the_total(): void
    {
        CarbonImmutable::setTestNow('2026-10-14 06:00:00');
        $supervisor = $this->user('Sup', Role::CRA_SUPERVISOR);
        $save = fn (User $user, array $values) => $this->actingAs($user)->putJson(route('pc-report.entries.update'), [
            'cra' => $this->regina->id, 'category' => null, 'date' => '2026-10-14', ...$values,
        ]);
        $show = fn (string $date) => $this->actingAs($supervisor)->get(route('pc-report.index', ['product' => 'clearsight', 'cra' => $this->regina->id, 'date' => $date]));

        $save($this->regina, ['page' => 'Clear Sight Hub', 'inquiries' => 12])->assertOk();
        $save($this->regina, ['page' => 'Clear Sight Hub', 'engagements' => 40])->assertOk();
        // Anyone who sees every CRA can type them too.
        $save($supervisor, ['page' => 'Clearsight Eye Drop', 'inquiries' => 5])->assertOk();

        $this->assertDatabaseHas('pc_report_entries', ['user_id' => $this->regina->id, 'page' => 'Clear Sight Hub', 'category' => '', 'inquiries' => 12, 'engagements' => 40]);
        $show('2026-10-14')
            ->assertViewHas('sets', fn ($sets) => collect($sets[$this->regina->id])->firstWhere('page', 'Clear Sight Hub') === [
                'page' => 'Clear Sight Hub', 'category' => '', 'tagging' => null, 'from' => '', 'to' => '', 'inquiries' => 12, 'engagements' => 40,
            ])
            ->assertSee('data-pc-total="inquiries">17<', false)
            ->assertSee('data-pc-total="engagements">40<', false);
        $show('2026-10-13')->assertViewHas('sets', fn ($sets) => collect($sets[$this->regina->id])->every(fn ($line) => $line['inquiries'] === null && $line['engagements'] === null));

        // An emptied box clears the count.
        $save($this->regina, ['page' => 'Clear Sight Hub', 'inquiries' => null])->assertOk();
        $this->assertDatabaseHas('pc_report_entries', ['user_id' => $this->regina->id, 'page' => 'Clear Sight Hub', 'inquiries' => null, 'engagements' => 40]);
    }

    public function test_counts_are_refused_for_another_cra_a_future_day_or_below_zero(): void
    {
        CarbonImmutable::setTestNow('2026-10-14 06:00:00');
        $save = fn (array $values) => $this->actingAs($this->regina)->putJson(route('pc-report.entries.update'), [
            'cra' => $this->regina->id, 'page' => 'Clear Sight Hub', 'category' => null, 'date' => '2026-10-14', ...$values,
        ]);

        $save(['cra' => $this->julyAnn->id, 'inquiries' => 3])->assertForbidden();
        $save(['date' => '2026-10-15', 'inquiries' => 3])->assertJsonValidationErrors(['date' => 'The date field must be a date before or equal to 2026-10-14.']);
        $save(['inquiries' => -1])->assertJsonValidationErrors(['inquiries' => 'The inquiries field must be at least 0.']);

        $this->assertDatabaseCount('pc_report_entries', 0);
    }

    public function test_cra_saves_their_own_tagging_in_capitals_but_not_the_time_or_another_cras_tagging(): void
    {
        $this->actingAs($this->regina)->putJson(route('pc-report.rows.update'), ['cra' => $this->regina->id, 'page' => 'Clear Sight Hub', 'category' => null, 'tagging' => 'sw1, sjj'])
            ->assertOk();
        $this->assertSame('SW1, SJJ', PcReportRow::firstWhere('user_id', $this->regina->id)->tagging);

        $this->actingAs($this->regina)->putJson(route('pc-report.rows.update'), ['cra' => $this->regina->id, 'page' => 'Clear Sight Hub', 'category' => null, 'from' => '2025-05', 'to' => '2025-06'])
            ->assertForbidden();
        $this->actingAs($this->regina)->putJson(route('pc-report.rows.update'), ['cra' => $this->julyAnn->id, 'page' => 'Clear Sight Hub', 'category' => null, 'tagging' => 'X'])
            ->assertForbidden();
        $this->actingAs($this->regina)->postJson(route('pc-report.split'), ['cra' => $this->regina->id, 'page' => 'Clear Sight Hub'])
            ->assertForbidden();
    }

    public function test_supervisor_splits_a_page_into_dormant_and_active_and_puts_it_back(): void
    {
        $supervisor = $this->user('Sup', Role::CRA_SUPERVISOR);
        $show = fn () => $this->actingAs($supervisor)->get(route('pc-report.index', ['product' => 'clearsight', 'cra' => $this->regina->id]));

        $this->actingAs($supervisor)->postJson(route('pc-report.split'), ['cra' => $this->regina->id, 'page' => 'Clear Sight Hub'])->assertOk();

        $show()->assertSeeInOrder(['PC - Clear Sight Hub - DORMANT', 'May 2024 – Dec 2025', 'PC - Clear Sight Hub - ACTIVE', 'Jan 2026 onwards']);

        $this->actingAs($supervisor)->deleteJson(route('pc-report.merge'), ['cra' => $this->regina->id, 'page' => 'Clear Sight Hub'])->assertOk();

        $show()->assertSee('PC - Clear Sight Hub')->assertDontSee('PC - Clear Sight Hub - DORMANT');
    }
}
