<?php

namespace Tests\Feature;

use App\Models\ExclusiveFsaAccount;
use App\Models\ExclusiveReservedLead;
use App\Models\Lead;
use App\Models\PancakeOrder;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\BacklogFill;
use App\Services\ExclusiveLeads;
use App\Services\LeadGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Joanna's FSA customers: a customer no CRA has sold to, whose latest order one of her FSA accounts sold, is hers alone.
 */
class ExclusiveFsaLeadsTest extends TestCase
{
    use RefreshDatabase;

    private const FSA = 'KIM GAN';

    private const OTHER_FSA = 'BRIE DLPRD';

    private User $joanna;

    private User $regina;

    private User $supervisor;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-06 09:00', 'Asia/Manila'));
        config([
            'services.shecom.key' => 'test-key',
            'exclusive_leads.quota_overrides' => [],
            'exclusive_leads.build_from' => '2026-11-06',
        ]);
        Product::create(['name' => 'Pterygium', 'srp' => 499]);
        $this->joanna = $this->cra('Joanna Rose', 'CRD ANNA PACLIBARE 2', '2026-11-01');
        $this->regina = $this->cra('Regina', 'CRD REJ VERGARA');
        $this->supervisor = User::create(['email' => 'lead@example.com', 'display_name' => 'Lead', 'role_id' => Role::firstWhere('slug', Role::CRA_SUPERVISOR)->id, 'is_active' => true]);
        ExclusiveFsaAccount::create(['user_id' => $this->joanna->id, 'seller_name' => self::FSA]);
    }

    private function cra(string $name, string $pancake, ?string $exclusiveFrom = null): User
    {
        return User::create([
            'email' => strtolower(strtok($name, ' ')).'@example.com', 'display_name' => $name, 'pancake_name' => $pancake,
            'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true, 'exclusive_from' => $exclusiveFrom,
        ]);
    }

    private function lead(string $day, string $type = Lead::TYPE_FSD): Lead
    {
        $this->seq++;

        return Lead::create([
            'order_id' => (string) (9000 + $this->seq), 'customer_name' => 'C'.$this->seq, 'phone_number' => '09170000'.str_pad((string) $this->seq, 3, '0', STR_PAD_LEFT),
            'product_name' => 'Pterygium', 'qty' => 1, 'delivered_date' => '2026-06-01', 'consumption_days' => 30,
            'est_out_of_stock_date' => $day, 'lead_type' => $type,
        ]);
    }

    private function soldBy(string $seller, Lead $lead, string $on, int $status = 3): void
    {
        PancakeOrder::create(['pancake_order_id' => 'P'.++$this->seq, 'ordered_on' => $on, 'ordered_at' => $on.' 02:00:00',
            'seller_name' => $seller, 'phone_key' => LeadGenerator::normalizePhone($lead->phone_number), 'status' => $status, 'total_price' => 300]);
    }

    private function sync(string $day): void
    {
        app(ExclusiveLeads::class)->syncCra($this->joanna->fresh(), CarbonImmutable::parse($day));
    }

    /**
     * @return array<int, string> lead id => source
     */
    private function listOn(string $day): array
    {
        return app(ExclusiveLeads::class)->sourcesFor($this->joanna, CarbonImmutable::parse($day))->all();
    }

    public function test_her_fsas_customer_goes_on_her_list_after_handled_and_before_random(): void
    {
        config(['exclusive_leads.daily_quota' => 2]);
        $random = $this->lead('2026-11-06');
        $fsa = $this->lead('2026-11-06');
        $this->soldBy(self::FSA, $fsa, '2026-06-01');
        $handled = $this->lead('2026-11-06');
        $this->soldBy($this->joanna->pancake_name, $handled, '2026-06-01');

        $this->sync('2026-11-06');

        // Two slots: handled first, then the FSA customer; the random lead waits.
        $this->assertEquals([$handled->id => 'handled', $fsa->id => 'fsa'], $this->listOn('2026-11-06'));
        $this->assertNull($random->fresh()->assigned_to);
    }

    public function test_only_the_latest_order_counts_and_any_cra_order_rules_the_customer_out(): void
    {
        config(['exclusive_leads.daily_quota' => 5]);
        // Her FSA first, another FSA last: not hers by FSA.
        $movedOn = $this->lead('2026-11-06');
        $this->soldBy(self::FSA, $movedOn, '2026-05-01');
        $this->soldBy(self::OTHER_FSA, $movedOn, '2026-06-01');
        // Another FSA first, hers last (a canceled order after it doesn't count): hers.
        $cameBack = $this->lead('2026-11-06');
        $this->soldBy(self::OTHER_FSA, $cameBack, '2026-05-01');
        $this->soldBy(self::FSA, $cameBack, '2026-06-01');
        $this->soldBy(self::OTHER_FSA, $cameBack, '2026-07-01', status: 6);
        // A former team account sold to them once: a CRA handled them, so never hers by FSA.
        $handledBefore = $this->lead('2026-11-06');
        $this->soldBy('CRD JOANNE MACLANG', $handledBefore, '2026-04-01');
        $this->soldBy(self::FSA, $handledBefore, '2026-06-01');

        $this->sync('2026-11-06');

        $list = $this->listOn('2026-11-06');
        $this->assertSame('random', $list[$movedOn->id]);
        $this->assertSame('fsa', $list[$cameBack->id]);
        $this->assertNotSame('fsa', $list[$handledBefore->id] ?? null);
    }

    public function test_the_segmentation_assignment_and_fill_keep_her_fsa_customers_for_her(): void
    {
        config(['segmentation.max_leads_per_cra' => 5]);
        $unowned = $this->lead('2026-11-06');
        $this->soldBy(self::OTHER_FSA, $unowned, '2026-06-01');
        $hersToday = $this->lead('2026-11-06');
        $this->soldBy(self::FSA, $hersToday, '2026-06-01');
        $hersInPool = $this->lead('2026-11-02');
        $this->soldBy(self::FSA, $hersInPool, '2026-06-01');

        $day = CarbonImmutable::parse('2026-11-06');
        app(LeadGenerator::class)->assign($day);
        app(BacklogFill::class)->fill($day, collect([$this->regina]));

        $this->assertSame($this->regina->id, $unowned->fresh()->assigned_to);
        $this->assertNull($hersToday->fresh()->assigned_to);
        $this->assertNull($hersInPool->fresh()->assigned_to);
    }

    public function test_the_month_holds_her_fsa_customers_before_random_leads(): void
    {
        config(['exclusive_leads.daily_quota' => 1, 'exclusive_leads.month_target' => 3, 'exclusive_leads.auto_hold' => 1000]);
        // Older random leads come first in the pool, yet the FSA customer is held ahead of them.
        $randoms = collect([1, 2, 3])->map(fn () => $this->lead('2026-11-02'));
        $fsa = $this->lead('2026-11-03');
        $this->soldBy(self::FSA, $fsa, '2026-06-01');

        $this->sync('2026-11-06');

        $handled = [...array_keys($this->listOn('2026-11-06')), ...ExclusiveReservedLead::pluck('lead_id')->all()];
        $this->assertContains($fsa->id, $handled);
        $this->assertSame('fsa', ExclusiveReservedLead::where('lead_id', $fsa->id)->value('source') ?? $this->listOn('2026-11-06')[$fsa->id]);
        $this->assertCount(3, $handled);
        $this->assertCount(2, array_intersect($handled, $randoms->pluck('id')->all()));
    }

    public function test_a_supervisor_adds_and_removes_an_fsa_account_and_the_customer_follows(): void
    {
        $lead = $this->lead('2026-11-06');
        $this->soldBy(self::OTHER_FSA, $lead, '2026-06-01');
        $this->actingAs($this->supervisor);

        $this->post(route('settings.exclusive-leads.fsa-accounts.store', $this->joanna), ['seller_name' => self::OTHER_FSA])->assertRedirect()->assertSessionHas('status');
        $account = ExclusiveFsaAccount::where('user_id', $this->joanna->id)->where('seller_name', self::OTHER_FSA)->firstOrFail();
        $this->assertSame($this->supervisor->id, $account->set_by);
        $this->sync('2026-11-06');
        $this->assertSame('fsa', $this->listOn('2026-11-06')[$lead->id]);

        $this->delete(route('settings.exclusive-leads.fsa-accounts.destroy', $account))->assertRedirect();
        $this->assertDatabaseMissing('exclusive_fsa_accounts', ['id' => $account->id]);
        $this->assertSame([], app(LeadGenerator::class)->ownersFor([LeadGenerator::normalizePhone($lead->phone_number)])['fsa']);
    }

    public function test_only_a_pancake_fsa_account_can_be_added_and_only_for_an_exclusive_cra(): void
    {
        $lead = $this->lead('2026-11-06');
        $this->soldBy($this->regina->pancake_name, $lead, '2026-06-01');
        $this->actingAs($this->supervisor);

        foreach (['NOBODY', $this->regina->pancake_name] as $notFsa) {
            $this->post(route('settings.exclusive-leads.fsa-accounts.store', $this->joanna), ['seller_name' => $notFsa])
                ->assertSessionHasErrors(['seller_name' => 'Pick an FSA account from the list: it has to be a Pancake seller that is not a CRD account.']);
        }
        $this->post(route('settings.exclusive-leads.fsa-accounts.store', $this->regina), ['seller_name' => self::FSA])->assertStatus(422);

        $this->assertSame([self::FSA], ExclusiveFsaAccount::pluck('seller_name')->all());
    }

    public function test_only_those_allowed_can_change_the_fsa_accounts(): void
    {
        $account = ExclusiveFsaAccount::firstOrFail();

        $this->post(route('settings.exclusive-leads.fsa-accounts.store', $this->joanna), ['seller_name' => self::FSA])->assertRedirect(route('login'));
        $this->actingAs($this->joanna)->delete(route('settings.exclusive-leads.fsa-accounts.destroy', $account))->assertForbidden();

        $this->assertDatabaseHas('exclusive_fsa_accounts', ['id' => $account->id]);
    }

    public function test_the_settings_dialog_lists_her_fsa_accounts(): void
    {
        $this->actingAs($this->supervisor)->get(route('exclusive-leads.index'))
            ->assertOk()
            ->assertSeeInOrder(['FSA accounts (1)', self::FSA, 'Add']);
    }
}
