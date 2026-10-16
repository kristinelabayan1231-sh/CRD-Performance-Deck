<?php

namespace Tests\Feature;

use App\Models\PancakeOrder;
use App\Models\PancakePage;
use App\Models\PcReportEntry;
use App\Models\PcReportRow;
use App\Models\Role;
use App\Models\User;
use App\Services\PancakeSync;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
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

    public function test_supervisor_saves_the_creation_update_time_for_the_day_picked_only(): void
    {
        CarbonImmutable::setTestNow('2026-10-16 06:00:00');
        $supervisor = $this->user('Sup', Role::CRA_SUPERVISOR);
        $show = fn (string $date) => $this->actingAs($supervisor)->get(route('pc-report.index', ['product' => 'clearsight', 'cra' => $this->regina->id, 'date' => $date]));
        $set = fn (string $date, ?string $from, ?string $to) => $this->actingAs($supervisor)->putJson(route('pc-report.rows.update'), [
            'cra' => $this->regina->id, 'page' => 'Clear Sight Hub', 'category' => null, 'date' => $date, 'from' => $from, 'to' => $to,
        ]);

        $set('2026-10-15', '2025-05', '2025-06')->assertOk();
        $set('2026-10-14', '2026-01', '2026-03')->assertOk();

        $this->assertDatabaseHas('pc_report_entries', ['user_id' => $this->regina->id, 'page' => 'Clear Sight Hub', 'category' => '', 'from_month' => '2025-05-01 00:00:00']);
        $show('2026-10-15')->assertOk()->assertSee('May – Jun 2025')->assertDontSee('Jan – Mar 2026');
        $show('2026-10-14')->assertOk()->assertSee('Jan – Mar 2026')->assertDontSee('May – Jun 2025');
        // A later day isn't carried over from the days before it.
        $show('2026-10-16')->assertOk()->assertDontSee('May – Jun 2025')->assertDontSee('Jan – Mar 2026')->assertSee('Not set');

        // Clearing a day leaves it Not set; the inquiries typed that day stay.
        $this->actingAs($supervisor)->putJson(route('pc-report.entries.update'), ['cra' => $this->regina->id, 'page' => 'Clear Sight Hub', 'category' => null, 'date' => '2026-10-15', 'inquiries' => 7])->assertOk();
        $set('2026-10-15', null, null)->assertOk();
        $this->assertDatabaseHas('pc_report_entries', ['page' => 'Clear Sight Hub', 'from_month' => null, 'inquiries' => 7]);

        $set('2026-10-17', '2025-05', '2025-06')->assertUnprocessable();
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
                // Regina has no Pancake account, so no orders.
                'orders' => null, 'orders_rows' => 1,
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
        $today = WorkingDate::realToday()->toDateString();
        $yesterday = WorkingDate::realToday()->subDay()->toDateString();

        $this->actingAs($supervisor)->postJson(route('pc-report.split'), ['cra' => $this->regina->id, 'page' => 'Clear Sight Hub', 'date' => $today])->assertOk();

        $show()->assertSeeInOrder(['PC - Clear Sight Hub - DORMANT', 'May 2024 – Dec 2025', 'PC - Clear Sight Hub - ACTIVE', 'Jan 2026 onwards']);
        // Only the day it was split on gets the usual months.
        $this->actingAs($supervisor)->get(route('pc-report.index', ['product' => 'clearsight', 'cra' => $this->regina->id, 'date' => $yesterday]))
            ->assertSee('PC - Clear Sight Hub - DORMANT')->assertDontSee('May 2024 – Dec 2025');

        $this->actingAs($supervisor)->deleteJson(route('pc-report.merge'), ['cra' => $this->regina->id, 'page' => 'Clear Sight Hub'])->assertOk();

        $show()->assertSee('PC - Clear Sight Hub')->assertDontSee('PC - Clear Sight Hub - DORMANT');
    }

    private int $orderSeq = 0;

    private function pageOrder(string $seller, ?string $page, string $day, int $status = 3, bool $nonCrd = false, ?string $pageId = null): void
    {
        PancakeOrder::create([
            'pancake_order_id' => (string) (5000 + ++$this->orderSeq), 'ordered_on' => $day, 'seller_name' => $seller,
            'status' => $status, 'total_price' => 500, 'page_name' => $page, 'page_id' => $pageId, 'non_crd' => $nonCrd,
        ]);
    }

    public function test_number_of_orders_counts_the_cras_orders_on_each_page_for_the_day_picked(): void
    {
        $this->regina->update(['pancake_name' => 'CRD Regina']);
        $this->julyAnn->update(['pancake_name' => 'CRD July Ann']);

        // Regina, Oct 1: two on Nasal Spray (page name in other case/spacing), one on Nasal Care.
        $this->pageOrder('CRD REGINA', 'Sinuxyl Nasal Spray', '2026-10-01');
        $this->pageOrder('CRD REGINA', 'sinuxyl  nasal spray', '2026-10-01');
        $this->pageOrder('CRD REGINA', 'Sinuxyl Nasal Care', '2026-10-01');
        // Not counted: canceled, deleted, no tracked product, another day, another CRA, another product's page.
        $this->pageOrder('CRD REGINA', 'Sinuxyl Nasal Spray', '2026-10-01', status: 6);
        $this->pageOrder('CRD REGINA', 'Sinuxyl Nasal Spray', '2026-10-01', status: 7);
        $this->pageOrder('CRD REGINA', 'Sinuxyl Nasal Spray', '2026-10-01', nonCrd: true);
        $this->pageOrder('CRD REGINA', 'Sinuxyl Nasal Spray', '2026-10-02');
        $this->pageOrder('CRD JULY ANN', 'Sinuxyl Nasal Spray', '2026-10-01');
        $this->pageOrder('CRD REGINA', 'Clear Sight Hub', '2026-10-01');

        $html = $this->actingAs($this->regina)->get(route('pc-report.index', ['product' => 'sinuxyl', 'date' => '2026-10-01']))
            ->assertOk()
            ->assertDontSee('saved before their Pancake page')
            ->getContent();

        $this->assertSame(['2', '1', '0'], $this->ordersCells($html));
        $this->assertStringContainsString('data-pc-total="orders">3<', $html);
    }

    public function test_orders_match_their_page_by_pancake_page_id_even_when_the_name_differs(): void
    {
        $this->regina->update(['pancake_name' => 'CRD Regina']);
        PancakePage::create(['name' => 'Sinuxyl Nasal Care', 'page_id' => '464', 'access_token' => 'token-1']);
        PancakePage::create(['name' => 'Sinuxyl Official Page', 'page_id' => '116', 'access_token' => 'token-2']);

        // Pancake names them differently on the order ("… PH", a capital I for the l).
        $this->pageOrder('CRD REGINA', 'Sinuxyl Nasal Care PH', '2026-10-01', pageId: '464');
        $this->pageOrder('CRD REGINA', 'Sinuxyl Nasal Care PH', '2026-10-01', pageId: '464');
        $this->pageOrder('CRD REGINA', 'Sinuxyl OfficiaI Page', '2026-10-01', pageId: '116');
        // Same name as a page Settings has an ID for, but another page's ID: not that page's order.
        $this->pageOrder('CRD REGINA', 'Sinuxyl Nasal Care', '2026-10-01', pageId: '999');

        $html = $this->actingAs($this->regina)->get(route('pc-report.index', ['product' => 'sinuxyl', 'date' => '2026-10-01']))
            ->assertOk()->getContent();

        // Nasal Spray (no ID in Settings, nothing ordered), Nasal Care, Official Page.
        $this->assertSame(['0', '2', '1'], $this->ordersCells($html));
    }

    public function test_a_split_pages_dormant_and_active_rows_share_one_number_of_orders(): void
    {
        $this->regina->update(['pancake_name' => 'CRD Regina']);
        foreach (PcReportRow::SPLIT as $category => [$from, $to]) {
            PcReportRow::create(['user_id' => $this->regina->id, 'page' => 'Sinuxyl Nasal Spray', 'category' => $category, 'from_month' => $from.'-01', 'to_month' => $to ? $to.'-01' : null]);
        }
        $this->pageOrder('CRD REGINA', 'Sinuxyl Nasal Spray', '2026-10-01');
        $this->pageOrder('CRD REGINA', 'Sinuxyl Nasal Spray', '2026-10-01');

        $html = $this->actingAs($this->regina)->get(route('pc-report.index', ['product' => 'sinuxyl', 'date' => '2026-10-01']))
            ->assertOk()->getContent();

        // Dormant + Active (one cell, both rows), Nasal Care, Official Page; the TOTAL counts the page once.
        $this->assertSame(['2', '0', '0'], $this->ordersCells($html));
        $this->assertStringContainsString('rowspan="2"', $html);
        $this->assertStringContainsString('data-pc-total="orders">2<', $html);
    }

    public function test_a_cra_without_a_pancake_account_shows_no_orders(): void
    {
        $html = $this->actingAs($this->regina)->get(route('pc-report.index', ['date' => '2026-10-01']))->assertOk()->getContent();

        $this->assertSame([], array_filter($this->ordersCells($html), fn (string $cell) => $cell !== '—'));
    }

    public function test_a_day_with_orders_saved_before_their_page_is_fetched_again_once(): void
    {
        $this->regina->update(['pancake_name' => 'CRD Regina']);
        $this->pageOrder('CRD REGINA', null, '2026-10-01');

        $pancake = Mockery::mock(PancakeSync::class);
        $pancake->shouldReceive('recheckIssuesLater');
        $pancake->shouldReceive('syncLater')->once()->withArgs(fn (CarbonImmutable $day, bool $force) => $day->toDateString() === '2026-10-01' && $force);
        $this->app->instance(PancakeSync::class, $pancake);

        $this->actingAs($this->regina)->get(route('pc-report.index', ['date' => '2026-10-01']))
            ->assertOk()->assertSee('saved before their Pancake page');
        $this->actingAs($this->regina)->get(route('pc-report.index', ['date' => '2026-10-01']))->assertOk();
    }

    /**
     * @return list<string>
     */
    private function ordersCells(string $html): array
    {
        preg_match_all('/data-pc-orders>(.*?)<\/td>/s', $html, $matches);

        return array_map(fn (string $cell) => trim(strip_tags($cell)), $matches[1]);
    }

    public function test_the_migration_moves_a_picked_creation_update_time_to_the_day_it_was_saved_but_not_the_usual_split_months(): void
    {
        Schema::table('pc_report_entries', fn ($table) => $table->dropColumn(['from_month', 'to_month']));
        $saved = ['created_at' => '2026-10-14 13:51:58', 'updated_at' => '2026-10-14 13:51:58'];
        DB::table('pc_report_rows')->insert([
            ['user_id' => $this->regina->id, 'page' => 'Clear Sight Hub', 'category' => 'ACTIVE', 'from_month' => '2025-07-01', 'to_month' => '2026-02-01', ...$saved],
            ['user_id' => $this->regina->id, 'page' => 'Clear Sight Hub', 'category' => 'DORMANT', 'from_month' => '2024-05-01', 'to_month' => '2025-12-01', ...$saved],
            ['user_id' => $this->julyAnn->id, 'page' => 'Clear Sight Hub', 'category' => 'ACTIVE', 'from_month' => '2026-01-01', 'to_month' => null, ...$saved],
        ]);

        (require database_path('migrations/2026_10_16_090000_add_creation_update_time_to_pc_report_entries_table.php'))->up();

        // 13:51 UTC is 21:51 on Oct 14 in Manila; the two rows still on the usual split months stay unset.
        $this->assertSame([['ACTIVE', '2026-10-14', '2025-07-01']], PcReportEntry::all()
            ->map(fn (PcReportEntry $entry) => [$entry->category, $entry->date->toDateString(), $entry->from_month->toDateString()])->all());
    }
}
