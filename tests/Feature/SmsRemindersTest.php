<?php

namespace Tests\Feature;

use App\Models\CustomerClaim;
use App\Models\DeliveredOrder;
use App\Models\DoNotText;
use App\Models\PancakeOrder;
use App\Models\Product;
use App\Models\RestockReminder;
use App\Models\Role;
use App\Models\Setting;
use App\Models\SmsReply;
use App\Models\SmsTemplate;
use App\Models\User;
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
        }

        return $phoneKey;
    }

    private function turnOn(): void
    {
        Setting::put(SmsSettings::ENABLED, '1');
    }

    public function test_customers_running_out_in_seven_real_days_are_listed_for_their_own_cra(): void
    {
        $due = $this->delivered('09170000001', cra: $this->anna);
        $this->delivered('09170000002', '2026-11-12', cra: $this->anna);
        $this->delivered('09170000003', '2026-11-14', cra: $this->anna);
        $twoPacks = $this->delivered('09170000004', '2026-10-29', qty: 2, cra: $this->mika);
        $this->delivered('09170000005', product: 'Haplunas Healing Eye Cream', cra: $this->anna);
        // The Working Date runs a month behind: reminders still follow the real date.
        Setting::put(WorkingDate::KEY, '2026-10-20');
        Setting::put(WorkingDate::ANCHOR, '2026-11-20');

        $this->artisan('reminders:build')->assertSuccessful();

        $reminders = RestockReminder::whereDate('remind_on', '2026-11-20')->get()->keyBy('phone_key');
        $this->assertEqualsCanonicalizing([$due, $twoPacks], $reminders->keys()->all());
        $this->assertSame($this->anna->id, $reminders[$due]->user_id);
        $this->assertSame($this->mika->id, $reminders[$twoPacks]->user_id);
        $this->assertSame('2026-11-27', $reminders[$due]->run_out_on->toDateString());
        $this->assertSame(RestockReminder::PENDING, $reminders[$due]->status);
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
        $this->delivered('09170000001', '2026-11-18', 'Sinuxyl');

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
        // Between the 9–11 AM and 2–4 PM windows, and after the last one.
        foreach (['11:00', '13:59', '16:00', '20:30'] as $time) {
            $this->travelTo(CarbonImmutable::parse("2026-11-20 {$time}", 'Asia/Manila'));
            $this->artisan('reminders:send');
        }
        Http::assertNothingSent();

        $this->travelTo(CarbonImmutable::parse('2026-11-20 14:00', 'Asia/Manila'));
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

        $this->travelTo(CarbonImmutable::parse('2026-11-20 09:29', 'Asia/Manila'));
        $this->artisan('reminders:send');
        $this->assertSame(RestockReminder::PENDING, RestockReminder::firstWhere('phone_key', $ticked)->status);

        $this->travelTo(CarbonImmutable::parse('2026-11-20 09:30', 'Asia/Manila'));
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
        $this->actingAs($supervisor)->get(route('sms-reminders.replies.index'))->assertOk()->assertSee('Magkano po?');
        $this->actingAs($this->mika)->get(route('sms-reminders.replies.index'))->assertOk()->assertDontSee('Magkano po?');
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
        $this->actingAs($this->anna)->get(route('sms-reminders.replies.index', ['show' => 'reorder']))
            ->assertOk()->assertSee('Maria Santos 1')->assertDontSee('Maria Santos 2')->assertDontSee('Magkano po?')->assertDontSee('Maria Santos 4');
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
        $this->assertSame(1, SmsSettings::sentToday($this->anna->id));

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

    public function test_customers_not_texted_on_their_day_carry_over_until_their_product_runs_out(): void
    {
        $this->turnOn();
        $this->anna->forceFill(['sms_auto_send' => true])->save();
        $waiting = $this->delivered('09170000001', cra: $this->anna);
        $reordered = $this->delivered('09170000002', cra: $this->anna);
        $this->artisan('reminders:build');

        // Two days later (Nov 22): Nov 20's customers not texted are still on the list; one has reordered since.
        $this->travelTo(CarbonImmutable::parse('2026-11-22 08:00', 'Asia/Manila'));
        $this->delivered('09170000002', '2026-11-21');
        $this->artisan('reminders:build');

        $this->assertSame(RestockReminder::SKIPPED, RestockReminder::firstWhere('phone_key', $reordered)->status);
        $carried = RestockReminder::firstWhere('phone_key', $waiting);
        $this->assertSame(RestockReminder::PENDING, $carried->status);
        $this->actingAs($this->anna)->get(route('sms-reminders.index'))->assertOk()
            ->assertSee($carried->customer_name)->assertSee('From Nov 20 · runs out Nov 27');

        // Automatic sending picks it up today.
        Http::fake(['trigger.macrodroid.com/*' => Http::response('ok')]);
        $this->travelTo(CarbonImmutable::parse('2026-11-22 09:30', 'Asia/Manila'));
        $this->artisan('reminders:send');
        $this->assertNotNull($carried->fresh()->handed_at);

        // After the product runs out it's no longer on the list.
        $carried->update(['status' => RestockReminder::PENDING, 'handed_at' => null]);
        $this->travelTo(CarbonImmutable::parse('2026-11-28 08:00', 'Asia/Manila'));
        $this->actingAs($this->anna)->get(route('sms-reminders.index'))->assertOk()->assertDontSee($carried->customer_name);
    }
}
