<?php

namespace Tests\Feature;

use App\Models\ExclusiveLeadTag;
use App\Models\ExclusiveListLead;
use App\Models\ExclusiveRemovedLead;
use App\Models\ExclusiveReservedLead;
use App\Models\Lead;
use App\Models\PancakeOrder;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\ExclusiveLeads;
use App\Services\PancakeClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class ExclusiveLeadTaggingTest extends TestCase
{
    use RefreshDatabase;

    private const TAG = 749;

    private User $joanna;

    private User $regina;

    private User $supervisor;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-06 09:00', 'Asia/Manila'));
        config(['exclusive_leads.build_from' => '2026-11-06', 'exclusive_leads.quota_overrides' => []]);
        Product::create(['name' => 'Pterygium', 'srp' => 499]);
        $this->joanna = $this->cra('Joanna Rose', 'CRD ANNA PACLIBARE 2', '2026-11-01');
        $this->regina = $this->cra('Regina', 'CRD REJ VERGARA');
        $this->supervisor = User::create(['email' => 'lead@example.com', 'display_name' => 'Lead', 'role_id' => Role::firstWhere('slug', Role::CRA_SUPERVISOR)->id, 'is_active' => true]);
    }

    private function cra(string $name, string $pancake, ?string $exclusiveFrom = null): User
    {
        return User::create([
            'email' => strtolower(strtok($name, ' ')).'@example.com', 'display_name' => $name, 'pancake_name' => $pancake,
            'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true, 'exclusive_from' => $exclusiveFrom,
        ]);
    }

    /**
     * An FSD lead due on $day whose POS order is synced with $tags.
     *
     * @param  list<int>  $tags
     */
    private function lead(?User $cra = null, array $tags = [], array $extra = []): Lead
    {
        $this->seq++;
        $lead = Lead::create([
            'order_id' => (string) (9000 + $this->seq), 'customer_name' => 'C'.$this->seq, 'phone_number' => '09170000'.str_pad((string) $this->seq, 3, '0', STR_PAD_LEFT),
            'product_name' => 'Pterygium', 'qty' => 1, 'delivered_date' => '2026-06-01', 'consumption_days' => 30,
            'est_out_of_stock_date' => '2026-11-05', 'lead_type' => Lead::TYPE_FSD, 'assigned_to' => $cra?->id, ...$extra,
        ]);
        PancakeOrder::create(['pancake_order_id' => $lead->order_id, 'ordered_on' => '2026-06-01', 'status' => 3, 'total_price' => 300, 'tags' => $tags]);

        return $lead;
    }

    private function listed(Lead $lead, string $source = ExclusiveLeads::RANDOM): Lead
    {
        $lead->forceFill(['assigned_to' => $this->joanna->id, 'listed_on' => '2026-11-06'])->save();
        ExclusiveListLead::create(['user_id' => $this->joanna->id, 'list_date' => '2026-11-06', 'lead_id' => $lead->id, 'source' => $source]);

        return $lead;
    }

    private function tagSeen(Lead $lead): void
    {
        ExclusiveLeadTag::create(['user_id' => $this->joanna->id, 'lead_id' => $lead->id, 'tagged_at' => now()]);
    }

    /**
     * @return array<int, string> lead id => source
     */
    private function listToday(): array
    {
        return app(ExclusiveLeads::class)->sourcesFor($this->joanna, CarbonImmutable::parse('2026-11-06'))->all();
    }

    private function pancake(): MockInterface
    {
        return $this->mock(PancakeClient::class);
    }

    public function test_an_open_lead_tagged_in_pos_joins_her_list_on_top_of_the_quota_and_the_rules_keep_it_there(): void
    {
        config(['exclusive_leads.daily_quota' => 1]);
        $this->pancake()->shouldNotReceive('addOrderTag', 'removeOrderTag');
        $this->listed($this->lead(null, [self::TAG]));
        $fromPool = $this->lead(null, [self::TAG], ['lead_type' => Lead::TYPE_CRD]);
        $fromRegina = $this->lead($this->regina, [self::TAG]);
        $worked = $this->lead($this->regina, [self::TAG], ['status' => 'active']);
        $untagged = $this->lead($this->regina);

        $this->artisan('leads:sync-exclusive-tags')->assertSuccessful();

        $this->assertSame(ExclusiveLeads::TAGGED, $this->listToday()[$fromPool->id]);
        $this->assertSame(ExclusiveLeads::TAGGED, $this->listToday()[$fromRegina->id]);
        $this->assertSame($this->joanna->id, $fromRegina->fresh()->assigned_to);
        $this->assertSame($this->regina->id, $worked->fresh()->assigned_to);
        $this->assertSame($this->regina->id, $untagged->fresh()->assigned_to);

        // A CRD lead with no reason to be hers would go back to the pool as a leftover; a tagged one stays.
        $this->travelTo(CarbonImmutable::parse('2026-11-07 09:00', 'Asia/Manila'));
        app(ExclusiveLeads::class)->syncCra($this->joanna->fresh(), CarbonImmutable::parse('2026-11-07'));
        $this->assertSame($this->joanna->id, $fromPool->fresh()->assigned_to);
    }

    public function test_the_tag_taken_off_in_pos_takes_an_open_lead_off_her_for_good_and_another_takes_its_place(): void
    {
        $this->pancake()->shouldNotReceive('addOrderTag', 'removeOrderTag');
        $removed = $this->listed($this->lead(null, [self::TAG]));
        $worked = $this->listed($this->lead(null, [self::TAG], ['status' => 'active']));
        $waiting = $this->lead(null, [self::TAG]);
        ExclusiveReservedLead::create(['user_id' => $this->joanna->id, 'month' => '2026-11-01', 'lead_id' => $waiting->id, 'source' => ExclusiveLeads::RANDOM]);
        collect([$removed, $worked, $waiting])->each(fn (Lead $lead) => $this->tagSeen($lead));
        PancakeOrder::whereIn('pancake_order_id', [$removed->order_id, $worked->order_id])->update(['tags' => []]);

        // A missing tag right after it was seen may be a sync that read the order before it: nothing moves yet.
        $this->artisan('leads:sync-exclusive-tags')->assertSuccessful();
        $this->assertSame($this->joanna->id, $removed->fresh()->assigned_to);

        $this->travel(31)->minutes();
        $this->artisan('leads:sync-exclusive-tags')->assertSuccessful();

        $this->assertNull($removed->fresh()->assigned_to);
        $this->assertArrayNotHasKey($removed->id, $this->listToday());
        $this->assertTrue(ExclusiveRemovedLead::where('lead_id', $removed->id)->where('via', ExclusiveRemovedLead::VIA_POS)->exists());
        $this->assertSame($this->joanna->id, $worked->fresh()->assigned_to);
        $this->assertNull(ExclusiveLeadTag::firstWhere('lead_id', $worked->id)->tagged_at);
        // The held lead takes the removed one's place today.
        $this->assertSame(ExclusiveLeads::RANDOM, $this->listToday()[$waiting->id]);

        // It never comes back to her by the rules.
        app(ExclusiveLeads::class)->syncCra($this->joanna->fresh(), CarbonImmutable::parse('2026-11-06'));
        $this->assertNull($removed->fresh()->assigned_to);
    }

    public function test_a_supervisor_removes_the_tag_on_the_website_and_the_lead_is_replaced(): void
    {
        $lead = $this->listed($this->lead(null, [398, self::TAG]));
        $this->tagSeen($lead);
        $next = $this->lead();
        ExclusiveReservedLead::create(['user_id' => $this->joanna->id, 'month' => '2026-11-01', 'lead_id' => $next->id, 'source' => ExclusiveLeads::RANDOM]);
        $this->pancake()->shouldReceive('removeOrderTag')->once()->with($lead->order_id, self::TAG)->andReturn(['before' => [398, self::TAG], 'after' => [398]]);

        $this->actingAs($this->supervisor)->delete(route('exclusive-leads.order-tag.destroy', $lead))
            ->assertSessionHas('status', "{$lead->customer_name}'s lead is off Joanna Rose's list and the CRD - ANNA (EXCLUSIVE LEADS) tag is off its order. Another lead took its place.");

        $this->assertNull($lead->fresh()->assigned_to);
        $this->assertSame([398], PancakeOrder::firstWhere('pancake_order_id', $lead->order_id)->tags);
        $this->assertSame([$next->id => ExclusiveLeads::RANDOM], $this->listToday());
        $this->assertFalse(ExclusiveLeadTag::where('lead_id', $lead->id)->exists());
    }

    public function test_only_supervisors_remove_and_a_failed_pancake_write_or_a_worked_lead_changes_nothing(): void
    {
        $lead = $this->listed($this->lead(null, [self::TAG]));
        $worked = $this->listed($this->lead(null, [self::TAG], ['status' => 'active']));
        $this->pancake()->shouldReceive('removeOrderTag')->once()->andThrow(new RuntimeException('Pancake is down.'));

        $this->actingAs($this->joanna)->delete(route('exclusive-leads.order-tag.destroy', $lead))->assertForbidden();

        $this->actingAs($this->supervisor)->delete(route('exclusive-leads.order-tag.destroy', $lead))
            ->assertSessionHas('status', "{$lead->customer_name}'s lead stays with Joanna Rose: the tag couldn't come off its order. Pancake is down.");
        $this->actingAs($this->supervisor)->delete(route('exclusive-leads.order-tag.destroy', $worked))
            ->assertSessionHas('status', "{$worked->customer_name}'s lead was already worked, so it stays with Joanna Rose.");

        $this->assertSame($this->joanna->id, $lead->fresh()->assigned_to);
        $this->assertSame($this->joanna->id, $worked->fresh()->assigned_to);
        $this->assertSame(0, ExclusiveRemovedLead::count());
    }

    public function test_her_months_leads_get_the_tag_and_a_lead_that_left_her_has_it_taken_off(): void
    {
        $alreadyTagged = $this->listed($this->lead(null, [self::TAG]));
        $listed = $this->listed($this->lead(null, [398]));
        $held = $this->lead();
        ExclusiveReservedLead::create(['user_id' => $this->joanna->id, 'month' => '2026-11-01', 'lead_id' => $held->id, 'source' => ExclusiveLeads::RANDOM]);
        $broken = $this->listed($this->lead());
        $left = $this->lead($this->regina, [self::TAG]);
        $this->tagSeen($left);

        $pancake = $this->pancake();
        $pancake->shouldReceive('addOrderTag')->once()->with($listed->order_id, self::TAG)->andReturn(['before' => [398], 'after' => [398, self::TAG]]);
        $pancake->shouldReceive('addOrderTag')->once()->with($held->order_id, self::TAG)->andReturn(['before' => [], 'after' => [self::TAG]]);
        $pancake->shouldReceive('addOrderTag')->once()->with($broken->order_id, self::TAG)->andThrow(new RuntimeException('Order not found.'));
        $pancake->shouldReceive('removeOrderTag')->once()->with($left->order_id, self::TAG)->andReturn(['before' => [self::TAG], 'after' => []]);
        $pancake->shouldNotReceive('addOrderTag')->with($alreadyTagged->order_id, Mockery::any());

        $this->artisan('leads:sync-exclusive-tags')->assertSuccessful();

        $this->assertSame([398, self::TAG], PancakeOrder::firstWhere('pancake_order_id', $listed->order_id)->tags);
        $this->assertNotNull(ExclusiveLeadTag::firstWhere('lead_id', $alreadyTagged->id)->tagged_at);
        $this->assertNotNull(ExclusiveLeadTag::firstWhere('lead_id', $held->id)->tagged_at);
        $this->assertNotNull(ExclusiveLeadTag::firstWhere('lead_id', $broken->id)->failed_at);
        $this->assertFalse(ExclusiveLeadTag::where('lead_id', $left->id)->exists());
        $this->assertSame([], PancakeOrder::firstWhere('pancake_order_id', $left->order_id)->tags);
        // The removed tag doesn't pull the lead back to her.
        $this->assertSame($this->regina->id, $left->fresh()->assigned_to);

        // A failed order is tried again only the next day.
        $this->artisan('leads:sync-exclusive-tags')->assertSuccessful();
    }

    public function test_the_dry_run_changes_nothing(): void
    {
        $this->pancake()->shouldNotReceive('addOrderTag', 'removeOrderTag');
        $tagged = $this->lead($this->regina, [self::TAG]);
        $this->listed($this->lead());

        $this->artisan('leads:sync-exclusive-tags', ['--dry-run' => true])
            ->expectsOutputToContain('Tagged in POS, would join her list: 1')
            ->assertSuccessful();

        $this->assertSame($this->regina->id, $tagged->fresh()->assigned_to);
        $this->assertSame(0, ExclusiveLeadTag::count());
    }
}
