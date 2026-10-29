<?php

namespace Tests\Feature;

use App\Models\DutyCount;
use App\Models\DutyLogin;
use App\Models\PancakeOrder;
use App\Models\PcReportEntry;
use App\Models\Role;
use App\Models\User;
use App\Services\DailyIncomeStatement;
use App\Services\LeadGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportSummaryTest extends TestCase
{
    use RefreshDatabase;

    private User $supervisor;

    private User $lhea;

    private User $regina;

    private User $rose;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Today is Fri, Oct 9: week 2 (Oct 8–14) of October.
        $this->travelTo(CarbonImmutable::parse('2026-10-09 18:00', 'Asia/Manila'));
        $this->withoutDefer();
        $this->supervisor = User::create(['email' => 'sup@example.com', 'role_id' => Role::firstWhere('slug', Role::CRA_SUPERVISOR)->id, 'is_active' => true]);
        $this->lhea = $this->cra('Lhea', 'CRD Lhei');
        $this->regina = $this->cra('Regina', 'CRD Rej Vergara');
        $this->rose = $this->cra('Rose', 'CRD Rose');
    }

    private function cra(string $name, ?string $pancake): User
    {
        return User::create([
            'email' => strtolower($name).'@example.com', 'display_name' => $name, 'pancake_name' => $pancake,
            'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true,
        ]);
    }

    private function order(string $seller, string $type, float $total, string $day, int $status = 2): void
    {
        $this->seq++;
        PancakeOrder::create([
            'pancake_order_id' => "P{$this->seq}", 'ordered_on' => $day, 'ordered_at' => $day.' 02:00:00', 'seller_name' => $seller,
            'customer_name' => "Buyer {$this->seq}", 'page_name' => 'Trusted Eye Care', 'status' => $status, 'total_price' => $total, 'conversion_type' => $type,
        ]);
    }

    private function sheetOrders(): void
    {
        $this->order('CRD LHEI', PancakeOrder::BROADCAST, 1000, '2026-10-01');
        $this->order('CRD LHEI', PancakeOrder::SEGMENTATION, 2000, '2026-10-01');
        $this->order('CRD LHEI', PancakeOrder::SEGMENTATION, 500, '2026-10-01', status: 6);   // canceled: in gross, not in orders
        $this->order('CRD REJ VERGARA', PancakeOrder::SEGMENTATION, 3000, '2026-10-01');
        $this->order('CRD LHEI', PancakeOrder::BROADCAST, 4000, '2026-10-02');
        $this->order('CRD REJ VERGARA', PancakeOrder::BROADCAST, 6000, '2026-10-08');         // week 2: month to date only
    }

    public function test_the_week_and_the_day_add_up_like_the_crd_sheet(): void
    {
        $this->sheetOrders();
        // Oct 1: two CRAs on duty, Oct 2: one, so the week averages 1.5 a day.
        DutyLogin::factory()->on('2026-10-01')->create(['user_id' => $this->lhea->id]);
        DutyLogin::factory()->on('2026-10-01')->create(['user_id' => $this->regina->id]);
        DutyLogin::factory()->on('2026-10-02')->create(['user_id' => $this->lhea->id]);

        $row = fn (array $report, User $cra) => $report['rows']->firstWhere('cra.id', $cra->id)['metrics'];

        $this->actingAs($this->supervisor)->get(route('report-summary.index', ['month' => '2026-10', 'week' => 1, 'date' => '2026-10-01']))->assertOk()
            ->assertViewHas('periodReport', function (array $week) use ($row) {
                $lhea = $row($week, $this->lhea);

                return [$lhea['bc_orders'], $lhea['sc_orders'], $lhea['bc_gross'], $lhea['sc_gross'], $lhea['gross'], $lhea['aov']] === [2, 1, 5000.0, 2500.0, 7500.0, 2500.0]
                    && $row($week, $this->rose)['aov'] === null
                    && [$week['total']['orders'], $week['total']['gross'], $week['total']['aov']] === [4, 10500.0, 2625.0]
                    && $week['on_duty'] === 1.5
                    && round($week['average_sales'], 2) === 3333.33;
            })
            ->assertViewHas('dayReport', fn (array $day) => $day['total']['gross'] === 6500.0 && $day['on_duty'] === 2.0 && $day['average_sales'] === 500.0)
            // The per-CRA lines are under the daily table only, as in the sheet.
            ->assertSeeTextInOrder(['1st week · October 1–7, 2026', 'Lhea', '₱7,500.00', '₱2,500.00', 'TOTAL', '₱10,500.00',
                'Daily sales report · Thu, Oct 1, 2026', 'TOTAL', 'No. of Customer Retention Associates (On Duty)', '2',
                'Average Sales per Customer Retention Associate', '₱500.00'])
            ->assertDontSeeText('₱3,333.33');
    }

    public function test_leads_engagement_and_conv_come_from_the_daily_is(): void
    {
        $this->sheetOrders();
        // Lhea's PC Report counts on a Sinuxyl page; a page in no product group is left out.
        foreach ([['2026-10-01', 'Sinuxyl Nasal Spray', 200, 100], ['2026-10-02', 'Sinuxyl Nasal Spray', 50, 40], ['2026-10-01', 'Some Other Page', 999, 999]] as [$date, $page, $inquiries, $engagements]) {
            PcReportEntry::create(['user_id' => $this->lhea->id, 'page' => $page, 'category' => '', 'date' => $date, 'inquiries' => $inquiries, 'engagements' => $engagements]);
        }
        $reach = fn (array $report) => array_map(fn (string $key) => $report['rows']->firstWhere('cra.id', $this->lhea->id)['metrics'][$key], ['is_leads', 'is_engagements', 'conv']);

        $this->actingAs($this->supervisor)->get(route('report-summary.index', ['month' => '2026-10', 'week' => 1, 'date' => '2026-10-01']))->assertOk()
            // The week: Lhea's 3 orders (the canceled one left out) over 140 engagements.
            ->assertViewHas('periodReport', fn (array $week) => $reach($week) === [250, 140, 3 / 140] && $week['total']['is_leads'] === 250)
            ->assertViewHas('dayReport', fn (array $day) => $reach($day) === [200, 100, 0.02]);
    }

    public function test_net_income_adds_up_the_daily_is_cra_card_and_ibsc_is_it_over_total_gross(): void
    {
        $this->sheetOrders();
        $statement = app(DailyIncomeStatement::class);
        $cras = LeadGenerator::cras();
        $cardNet = fn (User $cra, string $day) => $statement->day($cras, CarbonImmutable::parse($day))['cras'][$cra->id]['total']['net_income'];
        $weekNet = collect(range(1, 7))->sum(fn (int $day) => $cardNet($this->lhea, sprintf('2026-10-%02d', $day)));
        $metrics = fn (array $report, User $cra) => $report['rows']->firstWhere('cra.id', $cra->id)['metrics'];

        $this->actingAs($this->supervisor)->get(route('report-summary.index', ['month' => '2026-10', 'week' => 1, 'date' => '2026-10-01']))->assertOk()
            ->assertViewHas('periodReport', fn (array $week) => abs($metrics($week, $this->lhea)['net_income'] - $weekNet) < 0.01
                && abs($metrics($week, $this->lhea)['ibsc'] - $weekNet / 7500) < 0.000001
                && $metrics($week, $this->rose)['ibsc'] === null
                && abs($week['total']['net_income'] - $week['rows']->sum('metrics.net_income')) < 0.01)
            ->assertViewHas('dayReport', fn (array $day) => abs($metrics($day, $this->lhea)['net_income'] - $cardNet($this->lhea, '2026-10-01')) < 0.01)
            ->assertDontSeeText('Coming soon');
    }

    public function test_the_weekly_section_flips_to_month_to_date_and_opens_on_this_week_and_today(): void
    {
        $this->sheetOrders();

        $this->actingAs($this->supervisor)->get(route('report-summary.index'))->assertOk()
            ->assertViewHas('period', fn (array $period) => $period['from']->toDateString() === '2026-10-08' && $period['to']->toDateString() === '2026-10-09')
            ->assertViewHas('day', fn (CarbonImmutable $day) => $day->toDateString() === '2026-10-09')
            ->assertSeeText('No log-ins yet');

        $this->actingAs($this->supervisor)->get(route('report-summary.index', ['period' => 'mtd']))->assertOk()
            ->assertViewHas('periodReport', fn (array $mtd) => $mtd['total']['bc_gross'] === 11000.0 && $mtd['total']['gross'] === 16500.0)
            ->assertSeeText('Month to date · Oct 1–9, 2026');
    }

    public function test_a_days_on_duty_count_entered_by_hand_is_used_instead_of_its_log_ins(): void
    {
        $this->order('CRD LHEI', PancakeOrder::BROADCAST, 6000, '2026-10-08');
        DutyCount::create(['date' => '2026-10-08', 'count' => 5]);
        DutyLogin::factory()->on('2026-10-08')->create(['user_id' => $this->lhea->id]);
        DutyLogin::factory()->on('2026-10-09')->create(['user_id' => $this->lhea->id]);
        DutyLogin::factory()->on('2026-10-09')->create(['user_id' => $this->regina->id]);

        // Week 2 so far: Oct 8 = 5 (by hand), Oct 9 = 2 log-ins, so 3.5 a day.
        $this->actingAs($this->supervisor)->get(route('report-summary.index', ['date' => '2026-10-08']))->assertOk()
            ->assertViewHas('periodReport', fn (array $week) => $week['on_duty'] === 3.5)
            ->assertViewHas('dayReport', fn (array $day) => $day['on_duty'] === 5.0 && $day['average_sales'] === 1200.0);
    }

    public function test_only_users_with_the_report_summary_permission_can_open_it(): void
    {
        $this->actingAs($this->lhea)->get(route('report-summary.index'))->assertForbidden();
        $this->actingAs($this->supervisor)->get(route('report-summary.index', ['period' => 'month']))->assertSessionHasErrors('period');
    }
}
