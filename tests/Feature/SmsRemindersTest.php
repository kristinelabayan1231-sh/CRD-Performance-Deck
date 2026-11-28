<?php

namespace Tests\Feature;

use App\Models\CustomerClaim;
use App\Models\DeliveredOrder;
use App\Models\DoNotText;
use App\Models\ExclusiveListLead;
use App\Models\Lead;
use App\Models\PancakeOrder;
use App\Models\Product;
use App\Models\RestockReminder;
use App\Models\Role;
use App\Models\Setting;
use App\Models\SmsReply;
use App\Models\SmsTemplate;
use App\Models\User;
use App\Services\ExclusiveLeads;
use App\Services\RestockReminders;
use App\Support\SmsSettings;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmsRemindersTest extends TestCase
{
    use RefreshDatabase;

    private const SMS_LINK = 'https://trigger.macrodroid.com/device-1/sms';

    private User $anna;

    private User $mika;

    private Product $clearSight;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Real date Fri, Nov 20: reminders for products running out on Fri, Nov 27.
        $this->travelTo(CarbonImmutable::parse('2026-11-20 08:00', 'Asia/Manila'));
        $this->anna = $this->cra('Anna', 'https://trigger.macrodroid.com/device-1/call');
        $this->mika = $this->cra('Mika', 'https://trigger.macrodroid.com/device-2/call');
        $this->clearSight = Product::create(['name' => 'Clear Sight 3.0', 'keywords' => 'Clearsight', 'consumption_days' => 15]);
        Product::create(['name' => 'Haplunas', 'is_tsd' => true, 'keywords' => 'Haplunas Healing Eye Cream']);
    }

    private function cra(string $name, ?string $phoneLink = null): User
    {
        $cra = User::create([
            'email' => strtolower($name).'@example.com', 'display_name' => $name,
            'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true,
        ]);
        // A phone with a link has confirmed a text went out, so texts go to it (SmsSender::NOT_READY).
        $cra->forceFill(['call_webhook_url' => $phoneLink, 'sms_confirmed_at' => $phoneLink ? now() : null])->save();

        return $cra;
    }

    private function owner(): User
    {
        return User::firstOrCreate(['email' => config('access.super_admin_email')], ['role_id' => Role::superAdmin()->id, 'is_active' => true]);
    }

    private function supervisor(): User
    {
        return User::create(['email' => 'sup@example.com', 'display_name' => 'Sup', 'role_id' => Role::firstWhere('slug', Role::CRA_SUPERVISOR)->id, 'is_active' => true]);
    }

    /**
     * A delivered order of $product; 1 pc of Clear Sight (15 days) delivered Nov 13 runs out Nov 27.
     */
    private function delivered(string $phone, string $deliveredOn = '2026-11-13', string $product = 'Clear Sight 3.0', int $qty = 1, ?User $cra = null): string
    {
        $this->seq++;
        DeliveredOrder::create([
            'order_id' => "ORD-{$this->seq}", 'customer_name' => "Maria Santos {$this->seq}", 'phone_number' => $phone,
            'product_raw' => $product, 'qty' => $qty, 'delivered_date' => $deliveredOn, 'source' => DeliveredOrder::SOURCE_SHECOM,
        ]);

        $phoneKey = substr(preg_replace('/\D+/', '', $phone), -10);
        if ($cra) {
            CustomerClaim::firstOrCreate(['phone_key' => $phoneKey], ['user_id' => $cra->id, 'claimed_at' => now()]);
            $this->listLead($phone, $deliveredOn, $product, $qty, $cra);
        }

        return $phoneKey;
    }

    /**
     * A lead for the delivery on $cra's tracker list on its reminder day (run-out − 7, the lead day while no Working Date
     * gap is set), as the tracker would list it; products not on Product Consumption get no lead.
     */
    private function listLead(string $phone, string $deliveredOn, string $product, int $qty, User $cra): void
    {
        $catalogProduct = Product::where('is_tsd', false)->get()->first(fn (Product $item) => str_contains(strtolower($product), strtolower($item->name)) || strtolower((string) $item->keywords) === strtolower($product));
        if (! $catalogProduct?->consumption_days) {
            return;
        }
        $runOut = CarbonImmutable::parse($deliveredOn)->addDays($qty * $catalogProduct->consumption_days - 1);
        Lead::create([
            'order_id' => 'L-'.$this->seq, 'customer_name' => 'Maria Santos '.$this->seq, 'phone_number' => $phone, 'product_name' => $catalogProduct->name,
            'qty' => $qty, 'delivered_date' => $deliveredOn, 'consumption_days' => $catalogProduct->consumption_days,
            'est_out_of_stock_date' => $runOut->toDateString(), 'listed_on' => $runOut->subDays(7)->toDateString(),
            'lead_type' => Lead::TYPE_CRD, 'assigned_to' => $cra->id,
        ]);
    }

    private function turnOn(): void
    {
        Setting::put(SmsSettings::ENABLED, '1');
    }

    public function test_each_cras_tracker_list_for_the_lead_day_is_texted_by_that_cra(): void
    {
        // The Working Date runs a month behind: Nov 20 works the Oct 20 lead day.
        WorkingDate::startTomorrow(CarbonImmutable::parse('2026-10-21'));
        Product::create(['name' => 'Sinuxyl', 'consumption_days' => 15]);
        $rose = $this->cra('Rose', 'https://trigger.macrodroid.com/device-3/call');
        $rose->forceFill(['exclusive_from' => '2026-10-01'])->save();

        $annas = $this->lead('09170000001', $this->anna);
        $annasSecond = $this->lead('09170000001', $this->anna, product: 'Sinuxyl');
        $mikas = $this->lead('09170000002', $this->mika);
        $this->lead('09170000003', null);
        $this->lead('09170000004', $this->anna, '2026-10-21');
        $this->lead('09170000005', $this->anna, landline: true);
        $rosesLead = $this->lead('09170000006', $rose);
        ExclusiveListLead::create(['user_id' => $rose->id, 'list_date' => '2026-10-20', 'lead_id' => $rosesLead->id, 'source' => ExclusiveLeads::HANDLED]);
        // Assigned to Rose but not on her Exclusive Lead Tracker list for the day: not on any list.
        $this->lead('09170000007', $rose);

        $this->artisan('reminders:build')->assertSuccessful();

        $reminders = RestockReminder::whereDate('remind_on', '2026-11-20')->get()->keyBy('phone_key');
        $this->assertEqualsCanonicalizing(['9170000001', '9170000002', '9170000006'], $reminders->keys()->all());
        $this->assertSame($this->anna->id, $reminders['9170000001']->user_id);
        $this->assertSame($this->mika->id, $reminders['9170000002']->user_id);
        $this->assertSame($rose->id, $reminders['9170000006']->user_id);
        // One text for both of Anna's leads of the customer.
        $this->assertSame('Clear Sight 3.0 + Sinuxyl', $reminders['9170000001']->products);
        $this->assertEqualsCanonicalizing([$annas->id, $annasSecond->id], $reminders['9170000001']->lead_ids);
        $this->assertSame([$mikas->id], $reminders['9170000002']->lead_ids);
        $this->assertSame(RestockReminder::PENDING, $reminders['9170000001']->status);
    }

    /**
     * A lead on $cra's tracker list for $listedOn (none: unassigned).
     */
    private function lead(string $phone, ?User $cra, string $listedOn = '2026-10-20', string $product = 'Clear Sight 3.0', bool $landline = false): Lead
    {
        $this->seq++;

        return Lead::create([
            'order_id' => 'L-'.$this->seq, 'customer_name' => 'Juan Cruz '.$this->seq, 'phone_number' => $landline ? '028123456'.$this->seq : $phone,
            'product_name' => $product, 'qty' => 1, 'delivered_date' => '2026-10-06', 'consumption_days' => 15,
            'est_out_of_stock_date' => $listedOn, 'listed_on' => $listedOn, 'lead_type' => Lead::TYPE_CRD, 'assigned_to' => $cra?->id,
        ]);
    }

    public function test_customers_who_restocked_the_same_product_or_opted_out_are_skipped(): void
    {
        Product::create(['name' => 'Sinuxyl', 'consumption_days' => 15]);
        $redelivered = $this->delivered('09170000001', cra: $this->anna);
        $this->delivered('09170000001', '2026-11-18');
        $reorderedInPos = $this->delivered('09170000002', cra: $this->anna);
        PancakeOrder::create(['pancake_order_id' => 'POS-1', 'ordered_on' => '2026-11-19', 'phone_key' => $reorderedInPos,
            'items' => [['name' => 'Clear Sight 3.0', 'qty' => 1]]]);
        $otherProduct = $this->delivered('09170000003', cra: $this->anna);
        $this->delivered('09170000003', '2026-11-18', 'Sinuxyl');
        PancakeOrder::create(['pancake_order_id' => 'POS-2', 'ordered_on' => '2026-11-19', 'phone_key' => $otherProduct,
            'items' => [['name' => 'Sinuxyl', 'qty' => 1]]]);
        $optedOut = $this->delivered('09170000004', cra: $this->anna);
        DoNotText::create(['phone_key' => $optedOut, 'reason' => 'Replied STOP']);

        $this->artisan('reminders:build')->assertSuccessful();

        $this->assertDatabaseHas('restock_reminders', ['phone_key' => $redelivered, 'status' => RestockReminder::SKIPPED, 'error' => 'Already reordered']);
        $this->assertDatabaseHas('restock_reminders', ['phone_key' => $reorderedInPos, 'status' => RestockReminder::SKIPPED, 'error' => 'Already reordered']);
        $this->assertDatabaseHas('restock_reminders', ['phone_key' => $otherProduct, 'status' => RestockReminder::PENDING, 'products' => 'Clear Sight 3.0']);
        $this->assertDatabaseHas('restock_reminders', ['phone_key' => $optedOut, 'status' => RestockReminder::SKIPPED, 'error' => 'Opted out']);
    }

    public function test_only_the_products_not_yet_restocked_are_in_the_text(): void
    {
        Product::create(['name' => 'Sinuxyl', 'consumption_days' => 15]);
        $phoneKey = $this->delivered('09170000001', cra: $this->anna);
        $this->delivered('09170000001', product: 'Sinuxyl');
        // Restocked with 2 pcs: that order runs out Dec 17, outside the week, so only Clear Sight is texted.
        $this->delivered('09170000001', '2026-11-18', 'Sinuxyl', qty: 2);

        $this->artisan('reminders:build');

        $this->assertDatabaseHas('restock_reminders', ['phone_key' => $phoneKey, 'status' => RestockReminder::PENDING, 'products' => 'Clear Sight 3.0']);
    }

    public function test_rebuilding_leaves_customers_the_cra_already_left_out(): void
    {
        $phoneKey = $this->delivered('09170000001', cra: $this->anna);
        $this->artisan('reminders:build');
        RestockReminder::firstWhere('phone_key', $phoneKey)->update(['status' => RestockReminder::EXCLUDED]);

        $this->artisan('reminders:build');

        $this->assertSame(RestockReminder::EXCLUDED, RestockReminder::firstWhere('phone_key', $phoneKey)->status);
    }

    public function test_the_closest_fitting_template_is_used(): void
    {
        $phoneKey = $this->delivered('09170000001', cra: $this->anna);
        $this->artisan('reminders:build');
        $anna = ['user_id' => $this->anna->id];
        SmsTemplate::create([...$anna, 'name' => 'Any', 'body' => 'Default for {first_name}.']);
        SmsTemplate::create([...$anna, 'name' => 'Repeat', 'customer_type' => 'repeat', 'body' => 'Repeat text.']);
        SmsTemplate::create([...$anna, 'name' => 'Clear Sight', 'product_id' => $this->clearSight->id, 'body' => 'Hi {first_name}, {product} runs out {run_out_date}. {cra_name}']);
        SmsTemplate::create([...$anna, 'name' => 'Off', 'product_id' => $this->clearSight->id, 'customer_type' => 'first', 'body' => 'Off.', 'is_active' => false]);
        SmsTemplate::create([...$anna, 'name' => 'Follow-up', 'purpose' => SmsTemplate::FOLLOW_UP, 'product_id' => $this->clearSight->id, 'customer_type' => 'first', 'body' => 'Kumusta po?']);
        // Another CRA's template never goes to Anna's customers, however closely it fits.
        SmsTemplate::create(['user_id' => $this->mika->id, 'name' => 'Mika', 'product_id' => $this->clearSight->id, 'customer_type' => 'first', 'body' => 'Mika here.']);

        $message = app(RestockReminders::class)->messageFor(RestockReminder::firstWhere('phone_key', $phoneKey));

        $this->assertSame('Hi Maria, Clear Sight 3.0 runs out 27 Nov. Anna Reply STOP to opt out.', $message);
    }

    public function test_the_queue_sends_one_text_a_minute_from_each_cras_phone(): void
    {
        $this->turnOn();
        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00', 'Asia/Manila'));
        $first = $this->delivered('09170000001', cra: $this->anna);
        $second = $this->delivered('09170000002', cra: $this->anna);
        $mikas = $this->delivered('09170000003', cra: $this->mika);
        $this->artisan('reminders:build');
        RestockReminder::query()->update(['status' => RestockReminder::QUEUED, 'queued_at' => now()]);
        Http::preventStrayRequests();
        Http::fake(['trigger.macrodroid.com/*' => Http::response('ok')]);

        $this->artisan('reminders:send')->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), self::SMS_LINK) && $request['number'] === '09170000001'
            && str_contains($request['message'], 'Clear Sight 3.0'));
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://trigger.macrodroid.com/device-2/sms'));
        // Handed to the phones, waiting for each phone to confirm it went out.
        $this->assertNotNull(RestockReminder::firstWhere('phone_key', $first)->handed_at);
        $this->assertNull(RestockReminder::firstWhere('phone_key', $second)->handed_at);
        $this->assertNotNull(RestockReminder::firstWhere('phone_key', $mikas)->handed_at);
        $this->assertSame(RestockReminder::QUEUED, RestockReminder::firstWhere('phone_key', $first)->status);
    }

    public function test_nothing_is_sent_while_turned_off_or_outside_the_sending_windows(): void
    {
        $this->delivered('09170000001', cra: $this->anna);
        $this->artisan('reminders:build');
        RestockReminder::query()->update(['status' => RestockReminder::QUEUED, 'queued_at' => now()]);
        Http::preventStrayRequests();
        Http::fake(['trigger.macrodroid.com/*' => Http::response('ok')]);

        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00', 'Asia/Manila'));
        $this->artisan('reminders:send');
        $this->turnOn();
        // Between the 8:30–11 AM and 1:30–4 PM windows, and after the last one.
        foreach (['11:00', '13:29', '16:00', '20:30'] as $time) {
            $this->travelTo(CarbonImmutable::parse("2026-11-20 {$time}", 'Asia/Manila'));
            $this->artisan('reminders:send');
        }
        Http::assertNothingSent();

        $this->travelTo(CarbonImmutable::parse('2026-11-20 13:30', 'Asia/Manila'));
        $this->artisan('reminders:send');
        Http::assertSentCount(1);
    }

    public function test_automatic_sending_queues_only_ticked_customers_of_cras_who_turned_it_on(): void
    {
        $this->turnOn();
        $this->anna->forceFill(['sms_auto_send' => true])->save();
        $ticked = $this->delivered('09170000001', cra: $this->anna);
        $leftOut = $this->delivered('09170000002', cra: $this->anna);
        $mikas = $this->delivered('09170000003', cra: $this->mika);
        $this->artisan('reminders:build');
        RestockReminder::firstWhere('phone_key', $leftOut)->update(['status' => RestockReminder::EXCLUDED]);
        Http::fake(['trigger.macrodroid.com/*' => Http::response('ok')]);

        $this->travelTo(CarbonImmutable::parse('2026-11-20 08:29', 'Asia/Manila'));
        $this->artisan('reminders:send');
        $this->assertSame(RestockReminder::PENDING, RestockReminder::firstWhere('phone_key', $ticked)->status);

        $this->travelTo(CarbonImmutable::parse('2026-11-20 08:30', 'Asia/Manila'));
        $this->artisan('reminders:send');

        $this->assertNotNull(RestockReminder::firstWhere('phone_key', $ticked)->handed_at);
        $this->assertSame(RestockReminder::EXCLUDED, RestockReminder::firstWhere('phone_key', $leftOut)->status);
        $this->assertSame(RestockReminder::PENDING, RestockReminder::firstWhere('phone_key', $mikas)->status);
    }

    public function test_a_phone_that_does_not_answer_is_retried_then_marked_failed(): void
    {
        $this->turnOn();
        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00', 'Asia/Manila'));
        $phoneKey = $this->delivered('09170000001', cra: $this->anna);
        $this->artisan('reminders:build');
        RestockReminder::query()->update(['status' => RestockReminder::QUEUED, 'queued_at' => now()]);
        Http::fake(['trigger.macrodroid.com/*' => Http::response('down', 500)]);

        foreach (range(1, 3) as $try) {
            $this->artisan('reminders:send');
            $this->travel(6)->minutes();
        }

        $reminder = RestockReminder::firstWhere('phone_key', $phoneKey);
        $this->assertSame(RestockReminder::FAILED, $reminder->status);
        $this->assertSame(3, $reminder->attempts);
    }

    public function test_a_cra_sees_and_changes_only_their_own_customers(): void
    {
        $annas = $this->delivered('09170000001', cra: $this->anna);
        $mikas = $this->delivered('09170000003', cra: $this->mika);
        $this->artisan('reminders:build');
        $annaReminder = RestockReminder::firstWhere('phone_key', $annas);
        $mikaReminder = RestockReminder::firstWhere('phone_key', $mikas);

        $this->actingAs($this->anna)->get(route('sms-reminders.index'))
            ->assertOk()->assertSee($annaReminder->customer_name)->assertDontSee($mikaReminder->customer_name);
        $this->actingAs($this->anna)->patch(route('sms-reminders.update', $mikaReminder), ['include' => 0])->assertForbidden();

        $this->actingAs($this->anna)->patch(route('sms-reminders.update', $annaReminder), ['include' => 0, 'reason' => 'Asked not to be texted'])->assertRedirect();

        $this->assertSame(RestockReminder::EXCLUDED, $annaReminder->fresh()->status);
        $this->assertTrue(DoNotText::has($annas));
    }

    public function test_send_now_queues_the_cras_ticked_customers(): void
    {
        $this->turnOn();
        $ticked = $this->delivered('09170000001', cra: $this->anna);
        $leftOut = $this->delivered('09170000002', cra: $this->anna);
        $this->artisan('reminders:build');
        RestockReminder::firstWhere('phone_key', $leftOut)->update(['status' => RestockReminder::EXCLUDED]);

        $this->actingAs($this->anna)->post(route('sms-reminders.send-now'))->assertRedirect()->assertSessionHas('status');

        $this->assertSame(RestockReminder::QUEUED, RestockReminder::firstWhere('phone_key', $ticked)->status);
        $this->assertSame(RestockReminder::EXCLUDED, RestockReminder::firstWhere('phone_key', $leftOut)->status);
    }

    public function test_untick_all_keeps_carried_over_customers_on_the_list_and_tick_all_brings_them_back(): void
    {
        $carried = $this->delivered('09170000001', cra: $this->anna);
        $this->artisan('reminders:build');
        $this->travelTo(CarbonImmutable::parse('2026-11-21 07:30', 'Asia/Manila'));
        $todays = $this->delivered('09170000002', '2026-11-14', cra: $this->anna);
        $this->artisan('reminders:build');
        $carriedReminder = RestockReminder::firstWhere('phone_key', $carried);

        $this->actingAs($this->anna)->post(route('sms-reminders.bulk'), ['date' => '2026-11-21', 'include' => 0])->assertSessionHas('status');

        $this->assertSame(RestockReminder::EXCLUDED, $carriedReminder->fresh()->status);
        $this->actingAs($this->anna)->get(route('sms-reminders.index'))->assertOk()
            ->assertSee($carriedReminder->customer_name)->assertViewHas('counts', fn (array $counts) => $counts['excluded'] === 2);

        $this->actingAs($this->anna)->post(route('sms-reminders.bulk'), ['date' => '2026-11-21', 'include' => 1]);
        $this->assertSame(RestockReminder::PENDING, $carriedReminder->fresh()->status);
        $this->assertSame(RestockReminder::PENDING, RestockReminder::firstWhere('phone_key', $todays)->status);

        // Still on the list, unticked, on later days, and ticked from there.
        $this->actingAs($this->anna)->patch(route('sms-reminders.update', $carriedReminder), ['include' => 0]);
        $this->travelTo(CarbonImmutable::parse('2026-11-24 07:30', 'Asia/Manila'));
        $this->artisan('reminders:build');
        $this->assertSame(RestockReminder::EXCLUDED, $carriedReminder->fresh()->status);
        $this->actingAs($this->anna)->get(route('sms-reminders.index'))->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->getCollection()->contains($carriedReminder));
        $this->actingAs($this->anna)->patch(route('sms-reminders.update', $carriedReminder), ['include' => 1]);
        $this->assertSame(RestockReminder::PENDING, $carriedReminder->fresh()->status);
    }

    public function test_an_unticked_customer_is_never_texted_by_itself_and_leaves_the_list_once_they_reorder(): void
    {
        $this->turnOn();
        $this->anna->forceFill(['sms_auto_send' => true])->save();
        $reordered = $this->delivered('09170000001', cra: $this->anna);
        $again = $this->delivered('09170000002', cra: $this->anna);
        $this->artisan('reminders:build');
        $this->actingAs($this->anna)->post(route('sms-reminders.bulk'), ['date' => '2026-11-20', 'include' => 0]);

        // Next week one has reordered, and the other is on the list again with another product: one text, still unticked.
        $this->travelTo(CarbonImmutable::parse('2026-11-27 08:00', 'Asia/Manila'));
        $this->delivered('09170000001', '2026-11-21');
        Product::create(['name' => 'Sinuxyl', 'consumption_days' => 15]);
        $this->delivered('09170000002', '2026-11-20', 'Sinuxyl', cra: $this->anna);
        $this->artisan('reminders:build');

        $this->assertSame([RestockReminder::SKIPPED], RestockReminder::where('phone_key', $reordered)->pluck('status')->all());
        $this->assertSame([RestockReminder::SKIPPED, RestockReminder::EXCLUDED], RestockReminder::where('phone_key', $again)->orderBy('remind_on')->pluck('status')->all());
        $latest = RestockReminder::where('phone_key', $again)->latest('remind_on')->first();
        $this->assertEqualsCanonicalizing(['Clear Sight 3.0', 'Sinuxyl'], collect($latest->covers)->pluck('name')->all());

        Http::fake(['trigger.macrodroid.com/*' => Http::response('ok')]);
        $this->travelTo(CarbonImmutable::parse('2026-11-27 10:00', 'Asia/Manila'));
        $this->artisan('reminders:send');
        Http::assertNothingSent();
        $this->assertSame(RestockReminder::EXCLUDED, $latest->fresh()->status);
    }

    public function test_turning_automatic_sending_off_or_stop_takes_queued_texts_off_the_queue(): void
    {
        $this->turnOn();
        $annas = $this->delivered('09170000001', cra: $this->anna);
        $handed = $this->delivered('09170000002', cra: $this->anna);
        $mikas = $this->delivered('09170000003', cra: $this->mika);
        $this->artisan('reminders:build');
        $queue = fn () => RestockReminder::query()->update(['status' => RestockReminder::QUEUED, 'queued_at' => now()]);
        $statusOf = fn (string $phoneKey) => RestockReminder::firstWhere('phone_key', $phoneKey)->status;
        $queue();
        RestockReminder::where('phone_key', $handed)->update(['handed_at' => now()]);
        $this->anna->forceFill(['sms_auto_send' => true])->save();
        $this->actingAs($this->anna)->get(route('sms-reminders.index'))->assertOk()->assertSee('Stop 2 queued texts');

        // Off: what the phone hasn't taken is ticked again; another CRA's queue is untouched.
        $this->actingAs($this->anna)->put(route('sms-reminders.auto-send'), ['on' => 0])->assertSessionHasNoErrors();
        $this->assertSame([RestockReminder::PENDING, RestockReminder::QUEUED, RestockReminder::QUEUED], array_map($statusOf, [$annas, $handed, $mikas]));
        $this->artisan('reminders:send');
        $this->assertSame(RestockReminder::PENDING, $statusOf($annas));

        // Off from the schedule form too, but saving it while already off leaves a Send now alone.
        $queue();
        $this->actingAs($this->anna->refresh())->put(route('sms-reminders.schedule.update'), ['auto_send' => 0, 'use_default' => 1]);
        $this->assertSame(RestockReminder::QUEUED, $statusOf($annas));
        $this->anna->forceFill(['sms_auto_send' => true])->save();
        $this->actingAs($this->anna)->put(route('sms-reminders.schedule.update'), ['auto_send' => 0, 'use_default' => 1]);
        $this->assertSame(RestockReminder::PENDING, $statusOf($annas));

        // Stop: only their own, whoever's cra is sent.
        $queue();
        $this->actingAs($this->anna)->post(route('sms-reminders.stop'), ['cra' => $this->mika->id])->assertSessionHasNoErrors();
        $this->assertSame([RestockReminder::PENDING, RestockReminder::QUEUED], array_map($statusOf, [$annas, $mikas]));
    }

    public function test_leads_the_backlog_fill_adds_after_the_morning_build_join_that_cras_text_list_only(): void
    {
        $early = $this->delivered('09170000001', cra: $this->anna);
        $mikas = $this->delivered('09170000003', cra: $this->mika);
        $this->artisan('reminders:build');
        RestockReminder::where('phone_key', $mikas)->update(['status' => RestockReminder::PENDING]);
        $late = $this->delivered('09170000002', cra: $this->anna);
        $this->delivered('09170000004', cra: $this->mika);

        $this->artisan('reminders:build', ['--cra' => (string) $this->anna->id])->assertSuccessful();

        $this->assertEqualsCanonicalizing([$early, $late], RestockReminder::where('user_id', $this->anna->id)->where('status', RestockReminder::PENDING)->pluck('phone_key')->all());
        // The other CRA's list is as it was.
        $this->assertSame([$mikas], RestockReminder::where('user_id', $this->mika->id)->pluck('phone_key')->all());
        $this->assertSame(RestockReminder::PENDING, RestockReminder::firstWhere('phone_key', $mikas)->status);
    }

    public function test_a_queued_text_not_sent_is_checked_again_each_morning(): void
    {
        $reordered = $this->delivered('09170000001', cra: $this->anna);
        $moved = $this->delivered('09170000002', cra: $this->anna);
        $still = $this->delivered('09170000003', cra: $this->anna);
        $this->artisan('reminders:build');
        RestockReminder::query()->update(['status' => RestockReminder::QUEUED, 'queued_at' => now()]);

        $this->travelTo(CarbonImmutable::parse('2026-11-27 06:30', 'Asia/Manila'));
        $this->delivered('09170000001', '2026-11-21');
        Lead::whereIn('id', RestockReminder::firstWhere('phone_key', $moved)->lead_ids)->update(['assigned_to' => $this->mika->id]);
        $this->artisan('reminders:build');

        $this->assertSame(RestockReminder::SKIPPED, RestockReminder::firstWhere('phone_key', $reordered)->status);
        $this->assertSame([RestockReminder::PENDING, $this->mika->id], array_values(RestockReminder::firstWhere('phone_key', $moved)->only(['status', 'user_id'])));
        $this->assertSame([RestockReminder::QUEUED, $this->anna->id], array_values(RestockReminder::firstWhere('phone_key', $still)->only(['status', 'user_id'])));
    }

    public function test_a_failed_text_stays_on_the_list_unticked_until_the_cra_ticks_it(): void
    {
        $this->turnOn();
        $this->anna->forceFill(['sms_auto_send' => true])->save();
        $failed = $this->delivered('09170000001', cra: $this->anna);
        $reordered = $this->delivered('09170000002', cra: $this->anna);
        $this->artisan('reminders:build');
        RestockReminder::query()->update(['status' => RestockReminder::FAILED, 'error' => 'No confirm', 'handed_at' => now(), 'attempts' => 3]);

        $this->travelTo(CarbonImmutable::parse('2026-11-23 07:30', 'Asia/Manila'));
        $this->delivered('09170000002', '2026-11-21');
        $this->artisan('reminders:build');
        $this->assertSame(RestockReminder::SKIPPED, RestockReminder::firstWhere('phone_key', $reordered)->status);

        $reminder = RestockReminder::firstWhere('phone_key', $failed);
        $this->actingAs($this->anna)->get(route('sms-reminders.index', ['status' => 'failed']))->assertOk()
            ->assertSee($reminder->customer_name)->assertSee('Failed · 1')->assertSee('It may have gone out');

        // Never sent again by itself, nor with Tick all.
        Http::fake(['trigger.macrodroid.com/*' => Http::response('ok')]);
        $this->actingAs($this->anna)->post(route('sms-reminders.bulk'), ['date' => '2026-11-23', 'include' => 1]);
        $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00', 'Asia/Manila'));
        $this->artisan('reminders:send');
        Http::assertNothingSent();
        $this->assertSame(RestockReminder::FAILED, $reminder->fresh()->status);

        // Ticked by the CRA (or with its page): a new try.
        $this->actingAs($this->anna)->post(route('sms-reminders.bulk'), ['date' => '2026-11-23', 'include' => 1, 'ids' => [$reminder->id]]);
        $this->assertSame([RestockReminder::PENDING, 0, null, null], array_values($reminder->fresh()->only(['status', 'attempts', 'error', 'handed_at'])));
        $this->artisan('reminders:send');
        $this->assertNotNull($reminder->fresh()->handed_at);
    }

    public function test_untick_all_needs_one_cra(): void
    {
        $phoneKey = $this->delivered('09170000001', cra: $this->anna);
        $this->artisan('reminders:build');

        $this->actingAs($this->supervisor())->get(route('sms-reminders.index'))->assertOk()->assertDontSee('Untick all');
        $this->post(route('sms-reminders.bulk'), ['date' => '2026-11-20', 'include' => 0])->assertSessionHasErrors('send');
        $this->assertSame(RestockReminder::PENDING, RestockReminder::firstWhere('phone_key', $phoneKey)->status);

        $this->post(route('sms-reminders.bulk'), ['date' => '2026-11-20', 'include' => 0, 'cra' => $this->anna->id])->assertSessionHasNoErrors();
        $this->assertSame(RestockReminder::EXCLUDED, RestockReminder::firstWhere('phone_key', $phoneKey)->status);
    }

    public function test_the_list_shows_20_a_page_and_a_page_is_unticked_on_its_own(): void
    {
        foreach (range(1, 22) as $number) {
            $this->delivered('0917000'.str_pad((string) $number, 4, '0', STR_PAD_LEFT), cra: $this->anna);
        }
        $mikas = $this->delivered('09180000001', cra: $this->mika);
        $this->artisan('reminders:build');

        $page = $this->actingAs($this->anna)->get(route('sms-reminders.index'))->assertOk()->viewData('rows');
        $this->assertCount(20, $page->items());
        $this->assertCount(22, $this->actingAs($this->anna)->get(route('sms-reminders.index', ['per_page' => 50]))->viewData('rows')->items());
        $this->actingAs($this->anna)->get(route('sms-reminders.index', ['per_page' => 7]))->assertSessionHasErrors('per_page');

        // Another CRA's customer sent along stays as it is.
        $ids = [...$page->getCollection()->pluck('id'), RestockReminder::firstWhere('phone_key', $mikas)->id];
        $this->actingAs($this->anna)->post(route('sms-reminders.bulk'), ['date' => '2026-11-20', 'include' => 0, 'ids' => $ids])->assertSessionHasNoErrors();

        $this->assertSame(20, RestockReminder::where('status', RestockReminder::EXCLUDED)->count());
        $this->assertSame(RestockReminder::PENDING, RestockReminder::firstWhere('phone_key', $mikas)->status);
    }

    public function test_send_now_can_send_only_the_first_few_and_keeps_the_others_ticked(): void
    {
        $this->turnOn();
        $carried = $this->delivered('09170000003', cra: $this->anna);
        $this->artisan('reminders:build');
        $this->travelTo(CarbonImmutable::parse('2026-11-21 07:30', 'Asia/Manila'));
        $this->delivered('09170000001', '2026-11-14', cra: $this->anna);
        $this->delivered('09170000002', '2026-11-14', cra: $this->anna);
        $this->artisan('reminders:build');

        $this->actingAs($this->anna)->post(route('sms-reminders.send-now'), ['limit' => 0])->assertSessionHasErrors('limit');
        $this->actingAs($this->anna)->post(route('sms-reminders.send-now'), ['limit' => 2])->assertSessionHasNoErrors();

        $this->assertSame(RestockReminder::QUEUED, RestockReminder::firstWhere('phone_key', $carried)->status);
        $this->assertSame(2, RestockReminder::where('status', RestockReminder::QUEUED)->count());
        $this->assertSame(1, RestockReminder::where('status', RestockReminder::PENDING)->count());
    }

    public function test_a_cra_sets_her_own_daily_limit_and_her_phone_stops_there(): void
    {
        $this->turnOn();
        $this->actingAs($this->anna)->put(route('sms-reminders.daily-limit'), ['daily_limit' => 301])->assertSessionHasErrors('daily_limit');
        // A CRA's cra is ignored: she only changes her own.
        $this->actingAs($this->anna)->put(route('sms-reminders.daily-limit'), ['cra' => $this->mika->id, 'daily_limit' => 1])->assertSessionHasNoErrors();
        $this->assertSame(1, SmsSettings::dailyLimitFor($this->anna->fresh()));
        $this->assertSame(300, SmsSettings::dailyLimitFor($this->mika->fresh()));
        $this->assertFalse(SmsSettings::scheduleFor($this->anna->fresh())['custom']);

        foreach (['09170000001', '09170000002'] as $phone) {
            $this->delivered($phone, cra: $this->anna);
        }
        $this->artisan('reminders:build');
        RestockReminder::query()->update(['status' => RestockReminder::QUEUED, 'queued_at' => now()]);
        Http::fake(['trigger.macrodroid.com/*' => Http::response('ok')]);

        // One goes out in the morning; the afternoon window sends no more.
        foreach (['10:00', '10:01', '14:30'] as $time) {
            $this->travelTo(CarbonImmutable::parse("2026-11-20 {$time}", 'Asia/Manila'));
            $this->artisan('reminders:send');
        }
        $this->assertSame(1, RestockReminder::whereNotNull('handed_at')->count());

        // The next day's limit starts over.
        RestockReminder::whereNotNull('handed_at')->update(['status' => RestockReminder::SENT, 'sent_at' => now()]);
        $this->travelTo(CarbonImmutable::parse('2026-11-21 10:00', 'Asia/Manila'));
        $this->artisan('reminders:send');
        $this->assertSame(2, RestockReminder::whereNotNull('handed_at')->count());

        // Blank = the default again; the schedule form keeps or changes the limit with the schedule.
        $this->actingAs($this->anna)->put(route('sms-reminders.daily-limit'), ['daily_limit' => ''])->assertSessionHasNoErrors();
        $this->assertNull($this->anna->fresh()->sms_schedule);
        $this->actingAs($this->anna)->put(route('sms-reminders.schedule.update'), ['auto_send' => 1, 'use_default' => 1, 'daily_limit' => 40])->assertSessionHasNoErrors();
        $this->assertSame(40, SmsSettings::dailyLimitFor($this->anna->fresh()));
        $this->actingAs($this->anna)->get(route('sms-reminders.index'))->assertOk()->assertSee('of 40 sent today');

        // The team's limit lowered below hers wins.
        Setting::put(SmsSettings::DAILY_CAP, '25');
        $this->assertSame(25, SmsSettings::dailyLimitFor($this->anna->fresh()));
    }

    public function test_a_window_turned_off_sends_nothing_and_leaves_the_whole_limit_to_the_other(): void
    {
        $this->turnOn();
        $this->actingAs($this->anna)->put(route('sms-reminders.daily-limit'), ['daily_limit' => 40]);
        $this->assertSame(20, SmsSettings::perWindow($this->anna->fresh()));

        // A CRA's cra is ignored: she only changes her own.
        $this->actingAs($this->anna)->put(route('sms-reminders.window'), ['cra' => $this->mika->id, 'window' => 0, 'on' => 0])->assertSessionHasNoErrors();
        $this->assertSame(40, SmsSettings::perWindow($this->anna->fresh()));
        $this->assertSame(40, SmsSettings::dailyLimitFor($this->anna->fresh()));
        $this->assertSame(150, SmsSettings::perWindow($this->mika->fresh()));
        $this->actingAs($this->anna)->put(route('sms-reminders.window'), ['window' => 1, 'on' => 0])->assertSessionHasErrors('send');

        $phoneKey = $this->delivered('09170000001', cra: $this->anna);
        $this->artisan('reminders:build');
        RestockReminder::query()->update(['status' => RestockReminder::QUEUED, 'queued_at' => now()]);
        Http::fake(['trigger.macrodroid.com/*' => Http::response('ok')]);
        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00', 'Asia/Manila'));
        $this->artisan('reminders:send');
        $this->assertNull(RestockReminder::firstWhere('phone_key', $phoneKey)->handed_at);
        $this->travelTo(CarbonImmutable::parse('2026-11-20 14:30', 'Asia/Manila'));
        $this->artisan('reminders:send');
        $this->assertNotNull(RestockReminder::firstWhere('phone_key', $phoneKey)->handed_at);

        // The schedule form changes it too, and keeps at least one window on.
        $form = ['auto_send' => 1, 'use_default' => 1, 'daily_limit' => 40];
        $this->actingAs($this->anna)->put(route('sms-reminders.schedule.update'), [...$form, 'windows_on' => ['']])->assertSessionHasErrors('windows_on');
        $this->actingAs($this->anna)->put(route('sms-reminders.schedule.update'), [...$form, 'windows_on' => ['', '0']])->assertSessionHasNoErrors();
        $this->assertSame([1], SmsSettings::scheduleFor($this->anna->fresh())['windows_off']);
        $this->actingAs($this->anna)->put(route('sms-reminders.window'), ['window' => 1, 'on' => 1])->assertSessionHasNoErrors();
        $this->assertSame(['daily_limit' => 40], $this->anna->fresh()->sms_schedule);
        $this->actingAs($this->anna)->get(route('sms-reminders.index'))->assertOk();
        $this->actingAs($this->anna)->get(route('sms-reminders.schedule.index'))->assertOk()->assertSee('Second window');
    }

    public function test_a_reply_to_a_test_text_is_saved_for_a_day_without_opting_the_tester_out(): void
    {
        $token = $this->anna->issueRecordingToken();
        Http::fake(['trigger.macrodroid.com/*' => Http::response('ok')]);
        $this->actingAs($this->supervisor())->post(route('settings.sms-reminders.test'), ['cra' => $this->anna->id, 'number' => '0918 555 1234'])
            ->assertSessionHasNoErrors();

        $this->withToken($token)->postJson(route('api.sms-replies.store'), ['number' => '+639185551234', 'message' => 'YES'])->assertCreated();
        $this->withToken($token)->postJson(route('api.sms-replies.store'), ['number' => '+639185551234', 'message' => 'STOP'])->assertCreated();
        $this->travel(25)->hours();
        $this->withToken($token)->postJson(route('api.sms-replies.store'), ['number' => '+639185551234', 'message' => 'YES'])->assertStatus(202);

        $this->assertSame(2, SmsReply::where('user_id', $this->anna->id)->whereNull('restock_reminder_id')->count());
        $this->assertFalse(DoNotText::has('9185551234'));
    }

    public function test_replies_from_the_phone_are_saved_and_stop_opts_the_customer_out(): void
    {
        $token = $this->anna->issueRecordingToken();
        $phoneKey = $this->delivered('09170000001', cra: $this->anna);
        $this->artisan('reminders:build');
        RestockReminder::firstWhere('phone_key', $phoneKey)->update(['status' => RestockReminder::SENT, 'sent_at' => now()]);
        Http::fake(['trigger.macrodroid.com/*' => Http::response('ok')]);

        $this->postJson(route('api.sms-replies.store'), ['number' => '+639170000001', 'message' => 'yes'])->assertUnauthorized();
        $this->withToken('wrong')->postJson(route('api.sms-replies.store'), ['number' => '+639170000001', 'message' => 'yes'])->assertUnauthorized();
        $this->withToken($token)->postJson(route('api.sms-replies.store'), ['number' => '+639179999999', 'message' => 'Hi'])->assertStatus(202);
        $this->withToken($token)->postJson(route('api.sms-replies.store'), ['number' => '+639170000001', 'message' => 'Yes!'])->assertCreated();
        $this->withToken($token)->postJson(route('api.sms-replies.store'), ['number' => '+639170000001', 'message' => 'STOP'])->assertCreated();

        $this->assertSame([SmsReply::YES, SmsReply::STOP], SmsReply::orderBy('id')->pluck('kind')->all());
        $this->assertTrue(DoNotText::has($phoneKey));
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), self::SMS_LINK) && str_contains($request['message'], 'Hindi ka na makakatanggap'));
    }

    public function test_ticking_a_customer_who_replied_stop_lets_them_be_texted_again(): void
    {
        $token = $this->anna->issueRecordingToken();
        $phoneKey = $this->delivered('09170000001', cra: $this->anna);
        $this->artisan('reminders:build');
        $text = RestockReminder::firstWhere('phone_key', $phoneKey);
        $text->update(['status' => RestockReminder::SENT, 'sent_at' => now()]);
        $followUp = fn (string $day) => RestockReminder::create([...$text->only(['phone_key', 'phone_number', 'customer_name', 'product_id', 'products', 'covers', 'customer_type', 'run_out_on', 'user_id']),
            'remind_on' => $day, 'kind' => RestockReminder::FOLLOW_UP, 'follow_up_number' => 1, 'status' => RestockReminder::PENDING]);
        $next = $followUp('2026-11-27');
        Http::fake(['trigger.macrodroid.com/*' => Http::response('ok')]);
        $this->withToken($token)->postJson(route('api.sms-replies.store'), ['number' => '+639170000001', 'message' => 'STOP'])->assertCreated();
        $this->assertSame(RestockReminder::SKIPPED, $next->refresh()->status);

        $allow = route('sms-reminders.conversations.allow', $phoneKey);
        $this->actingAs($this->anna)->get(route('segmentation.index'))->assertOk()->assertSee('Replied STOP')->assertSee($allow, false);
        $this->get(route('sms-reminders.conversations.chat', $phoneKey))->assertOk()->assertSee($allow, false);
        $this->get(route('sms-reminders.index'))->assertOk()->assertSee($allow, false);
        $this->actingAs($this->mika)->post($allow)->assertForbidden();
        $this->assertTrue(DoNotText::has($phoneKey));

        $this->actingAs($this->anna)->post($allow)->assertSessionHas('status');
        $this->assertFalse(DoNotText::has($phoneKey));
        $this->assertSame(RestockReminder::PENDING, $next->refresh()->status);
        $this->get(route('segmentation.index'))->assertDontSee('Replied STOP')->assertSee('Texted Nov 20');

        // A second STOP stops them again; a text whose product was reordered meanwhile stays cancelled.
        $this->withToken($token)->postJson(route('api.sms-replies.store'), ['number' => '+639170000001', 'message' => 'stop'])->assertCreated();
        $this->assertTrue(DoNotText::has($phoneKey));
        $this->delivered('09170000001', deliveredOn: '2026-11-20');
        $this->actingAs($this->anna)->post($allow);
        $this->assertSame([RestockReminder::SKIPPED, 'Already reordered'], [$next->refresh()->status, $next->error]);
    }

    public function test_a_cra_opens_her_own_templates_and_only_managers_open_settings(): void
    {
        $this->actingAs($this->anna)->get(route('sms-reminders.templates.index'))->assertOk()->assertSee('Your name in texts');
        $this->actingAs($this->anna)->get(route('settings.sms-reminders.index'))->assertForbidden();

        $supervisor = $this->supervisor();
        $this->actingAs($supervisor)->get(route('sms-reminders.templates.index'))->assertOk();
        $settings = ['enabled' => 1, 'send_time' => '10:00', 'daily_cap' => 150, 'windows' => [['from' => '10:00', 'until' => '12:00'], ['from' => '', 'until' => '']]];
        $this->actingAs($supervisor)->put(route('settings.sms-reminders.update'), $settings)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue(SmsSettings::enabled());
        $this->assertSame('10:00', SmsSettings::sendTime());
        $this->assertSame(150, SmsSettings::dailyCap());
        $this->assertSame([['from' => '10:00', 'until' => '12:00']], SmsSettings::windows());

        $overlapping = [...$settings, 'windows' => [['from' => '09:00', 'until' => '11:00'], ['from' => '10:30', 'until' => '12:00']]];
        $this->actingAs($supervisor)->put(route('settings.sms-reminders.update'), $overlapping)->assertSessionHasErrors('windows.1.from');
        $backwards = [...$settings, 'windows' => [['from' => '11:00', 'until' => '09:00']]];
        $this->actingAs($supervisor)->put(route('settings.sms-reminders.update'), $backwards)->assertSessionHasErrors('windows.0.until');
        $this->assertSame([['from' => '10:00', 'until' => '12:00']], SmsSettings::windows());
        $this->actingAs($supervisor)->get(route('settings.sms-reminders.index'))->assertOk()->assertSee(self::SMS_LINK);
    }

    public function test_a_supervisor_sees_every_cras_customers_and_replies(): void
    {
        $annas = $this->delivered('09170000001', cra: $this->anna);
        $this->delivered('09170000003', cra: $this->mika);
        $this->artisan('reminders:build');
        $reminder = RestockReminder::firstWhere('phone_key', $annas);
        SmsReply::create(['user_id' => $this->anna->id, 'restock_reminder_id' => $reminder->id, 'phone_key' => $annas, 'phone_number' => '09170000001',
            'body' => 'Magkano po?', 'kind' => SmsReply::OTHER, 'received_at' => now()]);
        $supervisor = $this->supervisor();

        $this->actingAs($supervisor)->get(route('sms-reminders.index'))->assertOk()->assertSee('Maria Santos 1')->assertSee('Maria Santos 2');
        $this->actingAs($supervisor)->get(route('sms-reminders.replies.index'))->assertRedirect(route('sms-reminders.conversations.index', ['show' => 'reply']));
        $this->actingAs($supervisor)->get(route('sms-reminders.conversations.index'))->assertOk()->assertSee('Magkano po?');
        $this->actingAs($this->mika)->get(route('sms-reminders.conversations.index'))->assertOk()->assertDontSee('Magkano po?');
    }

    private function reply(User $cra, string $phone, string $kind, string $body, bool $handled = false): SmsReply
    {
        $phoneKey = $this->delivered($phone, cra: $cra);
        $this->artisan('reminders:build');

        return SmsReply::create(['user_id' => $cra->id, 'restock_reminder_id' => RestockReminder::firstWhere('phone_key', $phoneKey)->id,
            'phone_key' => $phoneKey, 'phone_number' => $phone, 'body' => $body, 'kind' => $kind, 'received_at' => now(),
            'handled_at' => $handled ? now() : null]);
    }

    public function test_a_cra_is_alerted_to_their_own_customers_who_want_to_reorder(): void
    {
        $this->reply($this->anna, '09170000001', SmsReply::YES, 'Yes');
        $this->reply($this->anna, '09170000002', SmsReply::YES, 'Oo', handled: true);
        $this->reply($this->anna, '09170000003', SmsReply::OTHER, 'Magkano po?');
        $this->reply($this->mika, '09170000004', SmsReply::YES, 'Yes po');

        $this->actingAs($this->anna)->get(route('dashboard'))->assertSee('1 customer wants to reorder');
        $this->actingAs($this->anna)->getJson(route('sms-reminders.reorder-alerts'))->assertOk()->assertJson(['count' => 1]);
        $this->actingAs($this->anna)->get(route('sms-reminders.conversations.index', ['show' => 'reorder']))
            ->assertOk()->assertSee('Maria Santos 1')->assertDontSee('Maria Santos 2')->assertDontSee('Magkano po?')->assertDontSee('Maria Santos 4');
    }

    public function test_a_reply_to_a_test_text_never_counts_as_a_customer_waiting(): void
    {
        SmsReply::create(['user_id' => $this->anna->id, 'phone_key' => '9170000009', 'phone_number' => '09170000009',
            'body' => 'Yes', 'kind' => SmsReply::YES, 'received_at' => now()]);

        $this->actingAs($this->anna)->get(route('dashboard'))->assertSee('data-reorder-alert data-count="0"', false);
        $this->actingAs($this->anna)->getJson(route('sms-reminders.reorder-alerts'))->assertOk()->assertJson(['count' => 0]);
        $this->actingAs($this->anna)->get(route('sms-reminders.index'))->assertOk()->assertViewHas('replies', 0);
        $this->actingAs($this->anna)->get(route('sms-reminders.conversations.index', ['show' => 'reorder']))
            ->assertOk()->assertViewHas('counts', fn (array $counts) => $counts['reply'] === 0 && $counts['reorder'] === 0)->assertSee('Nobody is waiting for a reorder call.');
    }

    public function test_an_unanswered_reply_stays_listed_and_counted_after_30_days(): void
    {
        $this->reply($this->anna, '09170000001', SmsReply::YES, 'Yes')->update(['received_at' => now()->subDays(31)]);

        foreach (['reply', 'reorder'] as $show) {
            $this->actingAs($this->anna)->get(route('sms-reminders.conversations.index', ['show' => $show]))
                ->assertOk()->assertViewHas('counts', fn (array $counts) => $counts['reply'] === 1 && $counts['reorder'] === 1)
                ->assertViewHas('threads', fn ($threads) => $threads->total() === 1);
        }
    }

    public function test_the_call_button_dials_a_reply_from_the_cras_own_phone(): void
    {
        $reply = $this->reply($this->anna, '09170000001', SmsReply::YES, 'Yes');
        Http::preventStrayRequests();
        Http::fake(['trigger.macrodroid.com/*' => Http::response('ok')]);

        $this->actingAs($this->mika)->postJson(route('sms-reminders.replies.call', $reply))->assertForbidden();
        $this->actingAs($this->anna)->postJson(route('sms-reminders.replies.call', $reply))->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://trigger.macrodroid.com/device-1/call') && $request['number'] === '09170000001');
    }

    public function test_a_follow_up_typed_by_hand_goes_out_right_away_from_the_customers_cras_phone(): void
    {
        $this->turnOn();
        $this->travelTo(CarbonImmutable::parse('2026-11-20 12:30', 'Asia/Manila'));
        $phoneKey = $this->delivered('09170000001', cra: $this->anna);
        $this->artisan('reminders:build');
        $reminder = RestockReminder::firstWhere('phone_key', $phoneKey);
        Http::preventStrayRequests();
        Http::fake(['trigger.macrodroid.com/*' => Http::response('ok')]);

        // Outside the sending windows, by a supervisor: still from Anna's phone, as typed.
        $this->actingAs($this->supervisor())->post(route('sms-reminders.text', $reminder), ['message' => 'Hi Maria, may stock pa po ba kayo?'])
            ->assertRedirect()->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), self::SMS_LINK)
            && $request['number'] === '09170000001' && $request['message'] === 'Hi Maria, may stock pa po ba kayo?');
        $this->assertDatabaseHas('sms_messages', ['restock_reminder_id' => $reminder->id, 'phone_user_id' => $this->anna->id]);
        // Typed texts are replies: not counted in the phone's daily limit.
        $this->assertSame(0, SmsSettings::sentToday($this->anna->id));

        // A reply to it is saved, though the reminder itself wasn't sent.
        $this->withToken($this->anna->issueRecordingToken())->postJson(route('api.sms-replies.store'), ['number' => '09170000001', 'message' => 'Yes'])->assertCreated();
    }

    public function test_a_follow_up_is_not_sent_to_another_cras_customer_an_opted_out_number_or_at_night(): void
    {
        $this->turnOn();
        $annas = $this->delivered('09170000001', cra: $this->anna);
        $optedOut = $this->delivered('09170000002', cra: $this->anna);
        $this->artisan('reminders:build');
        DoNotText::create(['phone_key' => $optedOut, 'reason' => 'Replied STOP']);
        Http::preventStrayRequests();
        Http::fake();
        $text = ['message' => 'Hello po'];

        $this->actingAs($this->mika)->post(route('sms-reminders.text', RestockReminder::firstWhere('phone_key', $annas)), $text)->assertForbidden();
        $this->actingAs($this->anna)->post(route('sms-reminders.text', RestockReminder::firstWhere('phone_key', $optedOut)), $text)->assertSessionHasErrors('text');
        $this->travelTo(CarbonImmutable::parse('2026-11-20 21:00', 'Asia/Manila'));
        $this->actingAs($this->anna)->post(route('sms-reminders.text', RestockReminder::firstWhere('phone_key', $annas)), $text)->assertSessionHasErrors('text');

        Http::assertNothingSent();
        $this->assertDatabaseCount('sms_messages', 0);
    }

    public function test_the_text_box_offers_every_active_template_starting_on_the_customers_reminder_template(): void
    {
        $this->delivered('09170000001', cra: $this->anna);
        $this->artisan('reminders:build');
        $reminderTemplate = SmsTemplate::create(['user_id' => $this->anna->id, 'name' => 'Clear Sight reminder', 'product_id' => $this->clearSight->id, 'body' => 'Hi {first_name}!']);
        SmsTemplate::create(['user_id' => $this->anna->id, 'name' => 'Kumusta follow-up', 'purpose' => SmsTemplate::FOLLOW_UP, 'body' => 'Kumusta po ang {product} mo?']);
        SmsTemplate::create(['user_id' => $this->anna->id, 'name' => 'Old one', 'body' => 'Old.', 'is_active' => false]);
        SmsTemplate::create(['user_id' => $this->mika->id, 'name' => 'Mikas spiel', 'purpose' => SmsTemplate::FOLLOW_UP, 'body' => 'Mika here.']);

        $this->actingAs($this->anna)->get(route('sms-reminders.index'))->assertOk()
            ->assertSee('Kumusta follow-up')
            ->assertSee('<option value="'.$reminderTemplate->id.'" selected>Clear Sight reminder</option>', false)
            ->assertSee('Hi Maria! Reply STOP to opt out.')
            ->assertDontSee('Old one')
            ->assertDontSee('Mikas spiel');
    }

    public function test_a_supervisor_saves_a_follow_up_template_for_the_cra_picked(): void
    {
        $this->actingAs($this->supervisor())->post(route('sms-reminders.templates.store', ['cra' => $this->mika->id]), [
            'name' => 'Kumusta', 'purpose' => SmsTemplate::FOLLOW_UP, 'body' => 'Kumusta po?', 'is_active' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $template = SmsTemplate::firstWhere('name', 'Kumusta');
        $this->assertTrue($template->isFollowUp());
        $this->assertSame($this->mika->id, $template->user_id);
    }

    public function test_a_template_saves_for_a_product_but_never_for_a_tsd_add_on(): void
    {
        $supervisor = $this->supervisor();

        $this->actingAs($supervisor)->post(route('sms-reminders.templates.store', ['cra' => $this->mika->id]), [
            'name' => 'Clear Sight', 'purpose' => SmsTemplate::REMINDER, 'product_id' => $this->clearSight->id, 'body' => 'Hi {first_name}!',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($this->clearSight->id, SmsTemplate::firstWhere('name', 'Clear Sight')->product_id);

        $this->actingAs($supervisor)->post(route('sms-reminders.templates.store', ['cra' => $this->mika->id]), [
            'name' => 'Haplunas', 'purpose' => SmsTemplate::REMINDER, 'product_id' => Product::firstWhere('name', 'Haplunas')->id, 'body' => 'Hi!',
        ])->assertSessionHasErrors('product_id');
    }

    public function test_a_cras_templates_are_her_own_and_saving_never_changes_another_cras(): void
    {
        $annas = SmsTemplate::create(['user_id' => $this->anna->id, 'name' => 'Annas spiel', 'body' => 'Hi from Anna.']);
        $mikas = SmsTemplate::create(['user_id' => $this->mika->id, 'name' => 'Mikas spiel', 'body' => 'Hi from Mika.']);
        $edit = ['name' => 'Changed', 'purpose' => SmsTemplate::REMINDER, 'body' => 'Changed.', 'is_active' => 1];

        $this->actingAs($this->anna)->get(route('sms-reminders.templates.index'))->assertOk()->assertSee('Annas spiel')->assertDontSee('Mikas spiel');
        // Asking for another CRA's list still shows her own.
        $this->actingAs($this->anna)->get(route('sms-reminders.templates.index', ['cra' => $this->mika->id]))->assertOk()->assertDontSee('Mikas spiel');
        $this->actingAs($this->anna)->put(route('sms-reminders.templates.update', $mikas), $edit)->assertForbidden();
        $this->actingAs($this->anna)->delete(route('sms-reminders.templates.destroy', $mikas))->assertForbidden();
        $this->actingAs($this->anna)->put(route('sms-reminders.templates.update', $annas), $edit)->assertRedirect();
        $this->actingAs($this->anna)->post(route('sms-reminders.templates.store', ['cra' => $this->mika->id]), [...$edit, 'name' => 'New'])->assertRedirect();

        $this->assertSame('Hi from Mika.', $mikas->fresh()->body);
        $this->assertSame('Changed.', $annas->fresh()->body);
        $this->assertSame($this->anna->id, SmsTemplate::firstWhere('name', 'New')->user_id);
    }

    public function test_a_cra_sets_the_name_her_texts_use(): void
    {
        $phoneKey = $this->delivered('09170000001', cra: $this->anna);
        $this->artisan('reminders:build');
        SmsTemplate::create(['user_id' => $this->anna->id, 'name' => 'Mine', 'body' => 'Si {cra_name} po ito.']);

        // A CRA can't name another CRA: asking for Mika still names herself.
        $this->actingAs($this->anna)->put(route('sms-reminders.templates.name', ['cra' => $this->mika->id]), ['sms_name' => 'Not Mika'])->assertRedirect();
        $this->assertSame('Mika', $this->mika->fresh()->smsName());
        $this->actingAs($this->anna)->put(route('sms-reminders.templates.name'), ['sms_name' => 'Ate Sky'])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Ate Sky', $this->anna->fresh()->smsName());
        $this->assertSame('Si Ate Sky po ito. Reply STOP to opt out.', app(RestockReminders::class)->messageFor(RestockReminder::firstWhere('phone_key', $phoneKey)));
        $this->actingAs($this->anna)->get(route('sms-reminders.index'))->assertOk()->assertSee('Ate Sky');

        // Blank goes back to her display name.
        $this->actingAs($this->anna)->put(route('sms-reminders.templates.name'), ['sms_name' => ''])->assertRedirect();
        $this->assertSame('Anna', $this->anna->fresh()->smsName());
    }

    public function test_a_text_is_sent_only_once_the_phone_confirms_it_and_an_unready_phone_gets_none(): void
    {
        $this->turnOn();
        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00', 'Asia/Manila'));
        $july = $this->cra('July', 'https://trigger.macrodroid.com/device-3/call');
        $july->forceFill(['sms_confirmed_at' => null])->save();
        $annas = $this->delivered('09170000001', cra: $this->anna);
        $julys = $this->delivered('09170000002', cra: $july);
        $this->artisan('reminders:build');
        RestockReminder::query()->update(['status' => RestockReminder::QUEUED, 'queued_at' => now()]);
        Http::fake(['trigger.macrodroid.com/*' => Http::response('ok')]);

        $this->artisan('reminders:send');

        // July's phone never confirmed a text: nothing goes to it, and her customer waits as "to send".
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), self::SMS_LINK) && $request['ref'] === (string) RestockReminder::firstWhere('phone_key', $annas)->id);
        $this->assertSame(RestockReminder::PENDING, RestockReminder::firstWhere('phone_key', $julys)->status);
        $this->actingAs($july)->post(route('sms-reminders.send-now'))->assertSessionHasErrors('send');

        // Anna's text is Sent only when her phone confirms it.
        $reminder = RestockReminder::firstWhere('phone_key', $annas);
        $this->assertSame(RestockReminder::QUEUED, $reminder->status);
        $this->withToken($this->anna->issueRecordingToken())->postJson(route('api.sms-sent.store'), ['ref' => (string) $reminder->id])->assertOk();
        $this->assertSame(RestockReminder::SENT, $reminder->fresh()->status);
        $this->assertNotNull($reminder->fresh()->sent_at);

        // A test text whose confirmation comes back makes July's phone ready.
        $julyToken = $july->issueRecordingToken();
        $this->actingAs($this->supervisor())->post(route('settings.sms-reminders.test'), ['cra' => $july->id, 'number' => '0918 555 1234'])->assertSessionHasNoErrors();
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'device-3/sms') && $request['ref'] === 'test');
        $this->withToken($julyToken)->postJson(route('api.sms-sent.store'), ['ref' => 'test'])->assertOk();
        $this->assertNotNull($july->fresh()->sms_confirmed_at);
    }

    public function test_a_text_the_phone_never_confirms_is_failed(): void
    {
        $this->turnOn();
        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00', 'Asia/Manila'));
        $phoneKey = $this->delivered('09170000001', cra: $this->anna);
        $this->artisan('reminders:build');
        RestockReminder::query()->update(['status' => RestockReminder::QUEUED, 'queued_at' => now()]);
        Http::fake(['trigger.macrodroid.com/*' => Http::response('ok')]);

        $this->artisan('reminders:send');
        $this->travel(16)->minutes();
        $this->artisan('reminders:send');

        $reminder = RestockReminder::firstWhere('phone_key', $phoneKey);
        $this->assertSame(RestockReminder::FAILED, $reminder->status);
        $this->assertStringContainsString("didn't confirm", $reminder->error);
        Http::assertSentCount(1);
    }

    public function test_customers_not_texted_on_their_day_carry_over_and_go_first_the_next_day(): void
    {
        $this->turnOn();
        $this->anna->forceFill(['sms_auto_send' => true])->save();
        $waiting = $this->delivered('09170000001', cra: $this->anna);
        $reordered = $this->delivered('09170000002', cra: $this->anna);
        $this->artisan('reminders:build');

        // A week later (Nov 27, after the product's out-of-stock day): Nov 20's customers not texted are still on the
        // list; one has reordered since. Today's own list has a new customer.
        $this->travelTo(CarbonImmutable::parse('2026-11-27 08:00', 'Asia/Manila'));
        $this->delivered('09170000002', '2026-11-21');
        $todays = $this->delivered('09170000003', '2026-11-20', cra: $this->anna);
        $this->artisan('reminders:build');

        $this->assertSame(RestockReminder::SKIPPED, RestockReminder::firstWhere('phone_key', $reordered)->status);
        $carried = RestockReminder::firstWhere('phone_key', $waiting);
        $this->assertSame(RestockReminder::PENDING, $carried->status);
        $this->actingAs($this->anna)->get(route('sms-reminders.index'))->assertOk()
            ->assertSee($carried->customer_name)->assertSee('Carried over from Nov 20');

        // Automatic sending: the carried-over customer goes before today's.
        Http::fake(['trigger.macrodroid.com/*' => Http::response('ok')]);
        $this->travelTo(CarbonImmutable::parse('2026-11-27 08:30', 'Asia/Manila'));
        $this->artisan('reminders:send');
        $this->assertNotNull($carried->fresh()->handed_at);
        $this->assertNull(RestockReminder::firstWhere('phone_key', $todays)->handed_at);
    }

    public function test_a_carried_over_text_on_no_tracker_list_is_skipped_but_a_follow_up_keeps_going(): void
    {
        $this->turnOn();
        // Texts built before they followed the tracker lists: no tracker lead, waiting (to send, queued or unticked).
        $old = fn (string $number, string $status) => RestockReminder::create(['phone_key' => substr($number, 1), 'phone_number' => $number, 'customer_name' => 'Old '.$status,
            'products' => 'Clear Sight 3.0', 'run_out_on' => '2026-11-23', 'remind_on' => '2026-11-18', 'user_id' => $this->anna->id, 'kind' => RestockReminder::REMINDER,
            'customer_type' => 'first', 'status' => $status, 'queued_at' => $status === RestockReminder::QUEUED ? now() : null]);
        $waiting = [$old('09170000005', RestockReminder::PENDING), $old('09170000006', RestockReminder::QUEUED), $old('09170000007', RestockReminder::EXCLUDED)];
        $followUp = RestockReminder::create(['phone_key' => '9170000009', 'phone_number' => '09170000009', 'customer_name' => 'Follow Up', 'products' => 'Clear Sight 3.0',
            'run_out_on' => '2026-11-27', 'remind_on' => '2026-11-18', 'user_id' => $this->anna->id, 'kind' => RestockReminder::FOLLOW_UP, 'follow_up_number' => 1,
            'customer_type' => 'first', 'status' => RestockReminder::PENDING]);
        $listed = $this->delivered('09170000001', cra: $this->anna);

        $this->artisan('reminders:build');

        foreach ($waiting as $text) {
            $this->assertSame([RestockReminder::SKIPPED, RestockReminders::NOT_ON_A_LIST], [$text->fresh()->status, $text->fresh()->error]);
        }
        $this->assertSame(RestockReminder::PENDING, $followUp->fresh()->status);
        $this->assertSame(RestockReminder::PENDING, RestockReminder::firstWhere('phone_key', $listed)->status);
    }

    public function test_each_window_sends_half_list_texts_and_half_follow_ups_and_a_kind_with_none_waiting_leaves_its_half(): void
    {
        $this->turnOn();
        // A 4-text daily cap over the two windows: 2 a window, 1 list text and 1 follow-up.
        Setting::put(SmsSettings::DAILY_CAP, '4');
        $this->anna->forceFill(['sms_marked_ready_by' => $this->owner()->id])->save();
        foreach (range(1, 3) as $n) {
            $this->delivered('0917000000'.$n, cra: $this->anna);
        }
        $this->artisan('reminders:build');
        $followUp = RestockReminder::create(['phone_key' => '9170000009', 'phone_number' => '09170000009', 'customer_name' => 'Follow Up', 'products' => 'Clear Sight 3.0',
            'run_out_on' => '2026-11-27', 'remind_on' => '2026-11-20', 'user_id' => $this->anna->id, 'kind' => RestockReminder::FOLLOW_UP, 'follow_up_number' => 1, 'customer_type' => 'first',
            'status' => RestockReminder::QUEUED, 'queued_at' => now()]);
        RestockReminder::where('kind', RestockReminder::REMINDER)->update(['status' => RestockReminder::QUEUED, 'queued_at' => now()]);
        Http::fake(['trigger.macrodroid.com/*' => Http::response('ok')]);
        $sentKinds = fn () => RestockReminder::where('status', RestockReminder::SENT)->orderBy('sent_at')->orderBy('id')->pluck('kind')->all();

        foreach (['08:30', '08:31', '08:32'] as $time) {
            $this->travelTo(CarbonImmutable::parse("2026-11-20 {$time}", 'Asia/Manila'));
            $this->artisan('reminders:send');
        }
        // Morning: one list text and the follow-up, then the window is full.
        $this->assertEqualsCanonicalizing([RestockReminder::REMINDER, RestockReminder::FOLLOW_UP], $sentKinds());
        $this->assertSame(RestockReminder::SENT, $followUp->fresh()->status);

        // Afternoon: no follow-up waiting, so list texts take both slots.
        foreach (['13:30', '13:31', '13:32'] as $time) {
            $this->travelTo(CarbonImmutable::parse("2026-11-20 {$time}", 'Asia/Manila'));
            $this->artisan('reminders:send');
        }
        $this->assertSame(4, RestockReminder::where('status', RestockReminder::SENT)->count());
        $this->assertSame(0, RestockReminder::where('status', RestockReminder::QUEUED)->count());
    }

    public function test_a_tracker_row_shows_its_text_and_the_reply(): void
    {
        $this->turnOn();
        $phoneKey = $this->delivered('09170000001', cra: $this->anna);
        $this->artisan('reminders:build');
        $text = RestockReminder::firstWhere('phone_key', $phoneKey);
        $lead = Lead::findOrFail($text->lead_ids[0]);
        $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00', 'Asia/Manila'));

        $badges = fn () => app(RestockReminders::class)->badgesFor([$lead]);
        $this->assertSame('To be texted', $badges()[$lead->id]['label']);
        $text->update(['status' => RestockReminder::SENT, 'sent_at' => now()]);
        $this->assertSame('Texted Nov 20', $badges()[$lead->id]['label']);
        SmsReply::create(['user_id' => $this->anna->id, 'restock_reminder_id' => $text->id, 'phone_key' => $phoneKey, 'phone_number' => '09170000001',
            'body' => 'Yes', 'kind' => SmsReply::YES, 'received_at' => now()]);
        $this->assertSame('Replied YES', $badges()[$lead->id]['label']);

        $this->actingAs($this->anna)->get(route('segmentation.index', ['date' => '2026-11-20']))->assertOk()->assertSee('Replied YES');
    }

    public function test_the_tracker_ticks_and_sends_each_text_and_opens_its_conversation(): void
    {
        $this->turnOn();
        $phoneKey = $this->delivered('09170000001', cra: $this->anna);
        $this->artisan('reminders:build');
        $text = RestockReminder::firstWhere('phone_key', $phoneKey);
        $update = route('sms-reminders.update', $text);
        $chat = route('sms-reminders.conversations.chat', $phoneKey);

        // Her tracker: one row of tabs, the sending bar, the text chips and each row's tick and conversation.
        $this->actingAs($this->anna)->get(route('segmentation.index'))->assertOk()
            ->assertSeeInOrder(['Daily', 'Weekly Segmentation', 'Conversations', "Today's texts", 'Templates', 'Schedule'], false)
            ->assertSee('aria-label="Sending"', false)->assertSee(route('sms-reminders.send-now'), false)
            ->assertSee('data-sms-filter="to-text"', false)->assertSee('data-sms-state="to-text"', false)
            ->assertSee($update, false)->assertSee('data-conversation-open="'.$chat.'"', false);
        $this->get($chat)->assertOk()->assertSee('Mark reordered');

        // Unticking from the tracker leaves them out of today's texts.
        $this->patch($update, ['include' => 0]);
        $this->assertSame(RestockReminder::EXCLUDED, $text->refresh()->status);
        $this->get(route('segmentation.index'))->assertSee('data-sms-state="unticked"', false);

        // Another CRA sees the text's state but can't tick it, send it or open its conversation.
        $this->actingAs($this->mika)->get(route('segmentation.index', ['cra' => $this->anna->id]))->assertOk()
            ->assertSee('Unticked')->assertDontSee($chat, false)->assertDontSee($update, false)->assertDontSee('aria-label="Sending"', false);
        $this->get($chat)->assertForbidden();
    }

    public function test_mark_reordered_cancels_the_waiting_texts_and_can_be_undone(): void
    {
        $phoneKey = $this->delivered('09170000001', cra: $this->anna);
        $this->artisan('reminders:build');
        $text = RestockReminder::firstWhere('phone_key', $phoneKey);
        $cancelled = fn () => [$text->refresh()->status, $text->error];

        $this->actingAs($this->anna)->post(route('sms-reminders.conversations.reordered', $phoneKey));
        $this->assertSame([RestockReminder::SKIPPED, RestockReminder::MARKED_REORDERED], $cancelled());
        $this->get(route('sms-reminders.conversations.chat', $phoneKey))->assertSee('Undo reordered')->assertDontSee('Mark reordered');

        $undo = route('sms-reminders.conversations.reordered.undo', $phoneKey);
        $this->actingAs($this->mika)->delete($undo)->assertForbidden();
        $this->actingAs($this->anna)->delete($undo)->assertSessionHas('status');
        $this->assertSame([RestockReminder::PENDING, null], $cancelled());
        $this->assertNull($text->reordered_at);
        $this->delete($undo)->assertSessionHasErrors('reordered');

        // A customer who replied STOP meanwhile stays off the texts.
        $this->post(route('sms-reminders.conversations.reordered', $phoneKey));
        DoNotText::create(['phone_key' => $phoneKey, 'reason' => 'Replied STOP']);
        $this->delete($undo);
        $this->assertSame(RestockReminder::SKIPPED, $cancelled()[0]);
    }

    public function test_an_exclusive_cra_reaches_the_sms_pages_from_her_tracker(): void
    {
        $this->anna->forceFill(['exclusive_from' => '2026-11-01'])->save();

        $this->actingAs($this->anna)->get(route('exclusive-leads.index'))->assertOk()
            ->assertSee('Leads and goals')->assertSee(route('sms-reminders.conversations.index'), false)->assertSee(route('sms-reminders.templates.index'), false);
        $this->get(route('sms-reminders.schedule.index'))->assertOk()->assertSee(route('exclusive-leads.index'), false);

        // A supervisor opens her texts from her list; another CRA, view only, doesn't.
        $this->actingAs($this->supervisor())->get(route('exclusive-leads.index', ['cra' => $this->anna->id]))->assertOk()
            ->assertSee(route('sms-reminders.conversations.index', ['cra' => $this->anna->id]), false);
        $this->actingAs($this->mika)->get(route('exclusive-leads.index'))->assertOk()->assertSee('View only.')->assertDontSee(route('sms-reminders.templates.index'), false);
    }

    public function test_a_phone_marked_ready_by_hand_sends_without_the_confirm_step(): void
    {
        $this->turnOn();
        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00', 'Asia/Manila'));
        $regina = $this->cra('Regina', 'https://trigger.macrodroid.com/device-4/call');
        $regina->forceFill(['sms_confirmed_at' => null])->save();
        $phoneKey = $this->delivered('09170000001', cra: $regina);
        $this->artisan('reminders:build');

        // Only Settings → SMS Reminders managers mark a phone ready.
        $this->actingAs($regina)->patch(route('settings.sms-reminders.ready', $regina), ['ready' => 1])->assertForbidden();
        $this->actingAs($this->owner())->patch(route('settings.sms-reminders.ready', $regina), ['ready' => 1])->assertRedirect();
        $this->assertNotNull($regina->fresh()->sms_confirmed_at);

        RestockReminder::query()->update(['status' => RestockReminder::QUEUED, 'queued_at' => now()]);
        Http::fake(['trigger.macrodroid.com/*' => Http::response('ok')]);
        $this->artisan('reminders:send');

        // Sent once the phone takes it, as before the confirm step; never failed for a missing confirmation.
        $reminder = RestockReminder::firstWhere('phone_key', $phoneKey);
        $this->assertSame(RestockReminder::SENT, $reminder->status);
        $this->travel(20)->minutes();
        $this->artisan('reminders:send');
        $this->assertSame(RestockReminder::SENT, $reminder->fresh()->status);

        // Marked not ready again: nothing goes to it.
        $this->actingAs($this->owner())->patch(route('settings.sms-reminders.ready', $regina), ['ready' => 0]);
        $this->assertNull($regina->fresh()->sms_confirmed_at);
    }

    public function test_a_cras_own_schedule_decides_when_her_texts_are_queued_and_sent(): void
    {
        $this->turnOn();
        // Anna: Mon–Thu and Sat only (not Fri, Nov 20), from 13:00, one window 15:00–16:00. Mika follows the default.
        $this->anna->forceFill(['sms_auto_send' => true, 'sms_schedule' => ['send_time' => '13:00', 'windows' => [['from' => '15:00', 'until' => '16:00']], 'days' => [1, 2, 3, 4, 6]]])->save();
        $this->mika->forceFill(['sms_auto_send' => true])->save();
        $annas = $this->delivered('09170000001', cra: $this->anna);
        $mikas = $this->delivered('09170000002', cra: $this->mika);
        $this->artisan('reminders:build');
        Http::fake(['trigger.macrodroid.com/*' => Http::response('ok')]);

        $this->travelTo(CarbonImmutable::parse('2026-11-20 15:00', 'Asia/Manila'));
        $this->artisan('reminders:send');
        $this->assertSame(RestockReminder::PENDING, RestockReminder::firstWhere('phone_key', $annas)->status);
        $this->assertSame(RestockReminder::QUEUED, RestockReminder::firstWhere('phone_key', $mikas)->status);

        // Saturday: queued from 13:00 but sent only inside her 15:00–16:00 window.
        $this->travelTo(CarbonImmutable::parse('2026-11-21 13:00', 'Asia/Manila'));
        $this->artisan('reminders:send');
        $this->assertSame(RestockReminder::QUEUED, RestockReminder::firstWhere('phone_key', $annas)->status);
        $this->assertNull(RestockReminder::firstWhere('phone_key', $annas)->handed_at);

        $this->travelTo(CarbonImmutable::parse('2026-11-21 15:00', 'Asia/Manila'));
        $this->artisan('reminders:send');
        $this->assertNotNull(RestockReminder::firstWhere('phone_key', $annas)->handed_at);
    }

    public function test_a_cra_sets_her_own_schedule_and_a_manager_sets_any_cras_in_settings(): void
    {
        $own = ['auto_send' => 1, 'use_default' => 0, 'send_time' => '10:15', 'days' => [1, 2, 3, 4, 5],
            'windows' => [['from' => '10:00', 'until' => '11:30'], ['from' => '', 'until' => '']]];

        $this->actingAs($this->anna)->get(route('sms-reminders.schedule.index'))->assertOk()->assertSee('My own schedule');
        // A CRA's ?cra= is ignored: she only changes her own.
        $this->actingAs($this->anna)->put(route('sms-reminders.schedule.update', ['cra' => $this->mika->id]), $own)->assertSessionHasNoErrors();
        $this->assertSame(['send_time' => '10:15', 'windows' => [['from' => '10:00', 'until' => '11:30']], 'days' => [1, 2, 3, 4, 5]], $this->anna->fresh()->sms_schedule);
        $this->assertTrue($this->anna->fresh()->sms_auto_send);
        $this->assertNull($this->mika->fresh()->sms_schedule);

        // Windows stay within the texting hours, and at least one day is picked.
        $this->actingAs($this->anna)->put(route('sms-reminders.schedule.update'), [...$own, 'windows' => [['from' => '06:00', 'until' => '07:00']]])->assertSessionHasErrors('windows.0.from');
        $this->actingAs($this->anna)->put(route('sms-reminders.schedule.update'), [...$own, 'windows' => [['from' => '19:00', 'until' => '21:00']]])->assertSessionHasErrors('windows.0.until');
        $this->actingAs($this->anna)->put(route('sms-reminders.schedule.update'), [...$own, 'days' => []])->assertSessionHasErrors('days');
        $this->actingAs($this->anna)->put(route('settings.sms-reminders.schedule', $this->anna), $own)->assertForbidden();

        // A manager sees it in Settings and puts her back on the default.
        $this->actingAs($this->owner())->get(route('settings.sms-reminders.index'))->assertOk()->assertSee('Mon–Fri · from 10:15 AM');
        $this->actingAs($this->owner())->put(route('settings.sms-reminders.schedule', $this->anna), ['auto_send' => 1, 'use_default' => 1])->assertSessionHasNoErrors();
        $this->assertNull($this->anna->fresh()->sms_schedule);
        $this->assertFalse(SmsSettings::scheduleFor($this->anna->fresh())['custom']);
    }

    public function test_a_customer_whose_product_already_ran_out_gets_the_general_text_not_the_cras_template(): void
    {
        $this->turnOn();
        SmsTemplate::create(['user_id' => $this->anna->id, 'name' => 'Mine', 'body' => 'Malapit na maubos ang {product} mo (~{run_out_date}).']);
        // The Working Date a month behind: Nov 20 texts Oct 20's list, which ran out a month ago.
        WorkingDate::startTomorrow(CarbonImmutable::parse('2026-10-21'));
        $this->listLead('09170000001', '2026-10-06', 'Clear Sight 3.0', 1, $this->anna);
        Lead::query()->update(['listed_on' => '2026-10-20']);
        $this->artisan('reminders:build');
        $text = RestockReminder::sole();
        $reminders = app(RestockReminders::class);

        $this->assertNull($reminders->templateFor($text));
        $this->assertStringContainsString('baka ubos na po', $reminders->messageFor($text));
        $this->assertStringNotContainsString('Malapit', $reminders->messageFor($text));

        $this->actingAs($this->owner())->put(route('settings.sms-reminders.update'), ['enabled' => 1, 'send_time' => '08:30', 'daily_cap' => 300,
            'windows' => [['from' => '08:30', 'until' => '11:00']], 'overdue_body' => 'Hi {first_name}, restock na po ng {product}?'])->assertSessionHasNoErrors();
        $this->assertStringStartsWith('Hi Maria, restock na po ng Clear Sight 3.0?', $reminders->messageFor($text));
    }
}
