<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadMove;
use App\Models\Role;
use App\Models\User;
use App\Services\BacklogFill;
use App\Services\SegmentationStats;
use App\Services\SegmentationSummary;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BacklogFillTest extends TestCase
{
    use RefreshDatabase;

    private User $july;

    private User $regina;

    private CarbonImmutable $day;

    private int $order = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['segmentation.max_leads_per_cra' => 5]);
        $cra = Role::firstWhere('slug', Role::CRA)->id;
        $this->july = User::create(['email' => 'july@example.com', 'display_name' => 'July Ann', 'role_id' => $cra, 'is_active' => true]);
        $this->regina = User::create(['email' => 'regina@example.com', 'display_name' => 'Regina', 'role_id' => $cra, 'is_active' => true]);
        $this->day = CarbonImmutable::parse('2026-09-19');
    }

    private function lead(string $day, ?User $cra = null, array $extra = []): Lead
    {
        return Lead::create([
            'order_id' => (string) ++$this->order, 'customer_name' => 'C'.$this->order, 'phone_number' => '917000'.$this->order,
            'product_name' => 'Pterygium', 'qty' => 1, 'delivered_date' => '2026-06-01', 'consumption_days' => 15,
            'est_out_of_stock_date' => $day, 'lead_type' => 'fsd', 'assigned_to' => $cra?->id, ...$extra,
        ]);
    }

    private function fill(User ...$cras): array
    {
        return app(BacklogFill::class)->fill($this->day, collect($cras));
    }

    public function test_own_leads_first_then_untouched_backlog_and_an_even_pool_share(): void
    {
        foreach (range(1, 3) as $i) {
            $this->lead('2026-09-19', $this->july);
        }
        $ownOld = $this->lead('2026-09-01', $this->july);
        $touched = $this->lead('2026-08-01', $this->july, ['status' => 'pjr_drop_call', 'contact_date' => '2026-08-02']);
        $pool = collect(range(1, 6))->map(fn ($i) => $this->lead('2026-06-'.str_pad($i, 2, '0', STR_PAD_LEFT)));
        $future = $this->lead('2026-10-05');

        $moved = $this->fill($this->july, $this->regina);

        // July: 3 own + 1 June pool + 1 own September backlog = 5. Regina: 5 from the pool.
        $this->assertSame([$this->july->id => 2, $this->regina->id => 5], $moved);
        $this->assertSame('2026-09-19', $ownOld->fresh()->listed_on->toDateString());
        $this->assertSame('2026-09-01', $ownOld->fresh()->est_out_of_stock_date->toDateString());
        $this->assertSame('2026-08-01', $touched->fresh()->listed_on->toDateString());
        $this->assertSame(6, $pool->filter(fn (Lead $l) => $l->fresh()->assigned_to !== null)->count());
        $this->assertNull($future->fresh()->assigned_to);

        foreach ([$this->july, $this->regina] as $cra) {
            $this->assertSame(5, Lead::where('assigned_to', $cra->id)->whereDate('listed_on', $this->day)->count());
        }
    }

    public function test_the_fill_mixes_every_month_with_waiting_leads(): void
    {
        foreach (range(1, 5) as $i) {
            $this->lead('2026-09-0'.$i, $this->july);
            $this->lead('2026-06-0'.$i);
            $this->lead('2026-07-0'.$i);
            $this->lead('2026-08-0'.$i);
        }

        $this->fill($this->july);

        // 5 picks in turn: June, July, August, September (her own), then June again.
        $months = Lead::where('assigned_to', $this->july->id)->whereDate('listed_on', $this->day)->get()
            ->countBy(fn (Lead $lead) => $lead->est_out_of_stock_date->format('Y-m'))->sortKeys()->all();
        $this->assertSame(['2026-06' => 2, '2026-07' => 1, '2026-08' => 1, '2026-09' => 1], $months);
        $this->assertSame(1, LeadMove::count());
    }

    public function test_leads_a_cra_worked_in_any_way_stay_on_their_day_with_their_cra(): void
    {
        $handled = collect([
            ['notes' => 'call after 5pm'], ['callback_date' => '2026-10-20'], ['feedback' => 'no_budget'],
            ['repeat_purchase' => 'reserve'], ['customer_tag' => 'hot'], ['contact_time' => '10'],
        ])->map(fn (array $field) => $this->lead('2026-09-01', $this->july, $field));
        $regina = $this->lead('2026-09-01', $this->regina);

        $this->assertSame([$this->july->id => 0], $this->fill($this->july));

        foreach ($handled as $lead) {
            $this->assertSame('2026-09-01', $lead->fresh()->listed_on->toDateString());
            $this->assertSame($this->july->id, $lead->fresh()->assigned_to);
        }
        // Another CRA's lead is never handed to someone else.
        $this->assertSame($this->regina->id, $regina->fresh()->assigned_to);
        $this->assertSame('2026-09-01', $regina->fresh()->listed_on->toDateString());
    }

    public function test_a_cra_is_filled_once_a_day_and_processed_leads_still_count(): void
    {
        foreach (range(1, 5) as $i) {
            $this->lead('2026-06-01');
        }
        $this->fill($this->july);

        // Working leads doesn't free room for more.
        Lead::where('assigned_to', $this->july->id)->update(['status' => 'active']);
        foreach (range(1, 5) as $i) {
            $this->lead('2026-06-02');
        }

        $this->assertSame([], $this->fill($this->july));
        $this->assertSame(5, Lead::where('assigned_to', $this->july->id)->count());
    }

    public function test_an_untouched_moved_lead_moves_again_on_a_later_day(): void
    {
        $lead = $this->lead('2026-06-01');
        $this->fill($this->july);

        $this->day = CarbonImmutable::parse('2026-09-20');
        $this->fill($this->july);

        $this->assertSame('2026-09-20', $lead->fresh()->listed_on->toDateString());
        $this->assertSame($this->july->id, $lead->fresh()->assigned_to);
    }

    public function test_a_days_total_stays_the_same_after_its_untouched_leads_move(): void
    {
        $this->day = CarbonImmutable::parse('2026-09-18');
        foreach (range(1, 5) as $i) {
            $this->lead('2026-06-01');
        }
        $this->fill($this->july);
        Lead::where('assigned_to', $this->july->id)->orderBy('id')->limit(2)->update(['status' => 'active']);

        // Next day: the 3 untouched ones move on to Sep 19.
        $this->day = CarbonImmutable::parse('2026-09-19');
        $this->fill($this->july);

        $sep18 = CarbonImmutable::parse('2026-09-18');
        $summary = app(SegmentationSummary::class)->for(
            Lead::whereDate('listed_on', $sep18), collect([$this->july]), LeadMove::departed($sep18, $sep18));

        $this->assertSame(5, $summary['leads']);
        $this->assertSame(2, $summary['catered']);
        $this->assertSame(3, $summary['pending']);
        $this->assertSame(5, $summary['cras'][0]['leads']);
        $this->assertSame(3, Lead::whereDate('listed_on', '2026-09-19')->count());

        // The dashboard's FSD Leads base for Sep 18 is unchanged too.
        $this->assertSame(5, app(SegmentationStats::class)->customers(collect([$this->july]), $sep18, $sep18, $sep18, $sep18)['leads']['retained']);
    }

    public function test_listed_on_follows_the_out_of_stock_date_until_moved(): void
    {
        $lead = $this->lead('2026-09-10', $this->july);
        $lead->update(['est_out_of_stock_date' => '2026-09-11']);
        $this->assertSame('2026-09-11', $lead->fresh()->listed_on->toDateString());

        $lead->update(['listed_on' => '2026-09-19']);
        $lead->fresh()->update(['est_out_of_stock_date' => '2026-09-12']);
        $this->assertSame('2026-09-19', $lead->fresh()->listed_on->toDateString());
    }
}
