<?php

namespace Tests\Feature;

use App\Models\CustomerClaim;
use App\Models\DeliveredOrder;
use App\Models\DoNotText;
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
        $cra->forceFill(['call_webhook_url' => $phoneLink])->save();

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

    public function test_customers_who_reordered_or_opted_out_are_skipped(): void
    {
        $reordered = $this->delivered('09170000001', cra: $this->anna);
        $this->delivered('09170000001', '2026-11-18', 'Sinuxyl');
        $optedOut = $this->delivered('09170000002', cra: $this->anna);
        DoNotText::create(['phone_key' => $optedOut, 'reason' => 'Replied STOP']);

        $this->artisan('reminders:build')->assertSuccessful();

        $this->assertDatabaseHas('restock_reminders', ['phone_key' => $reordered, 'status' => RestockReminder::SKIPPED, 'error' => 'Already reordered']);
        $this->assertDatabaseHas('restock_reminders', ['phone_key' => $optedOut, 'status' => RestockReminder::SKIPPED, 'error' => 'Opted out']);
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
        SmsTemplate::create(['name' => 'Any', 'body' => 'Default for {first_name}.']);
        SmsTemplate::create(['name' => 'Repeat', 'customer_type' => 'repeat', 'body' => 'Repeat text.']);
        SmsTemplate::create(['name' => 'Clear Sight', 'product_id' => $this->clearSight->id, 'body' => 'Hi {first_name}, {product} runs out {run_out_date}. {cra_name}']);
        SmsTemplate::create(['name' => 'Off', 'product_id' => $this->clearSight->id, 'customer_type' => 'first', 'body' => 'Off.', 'is_active' => false]);

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
        $this->assertSame(RestockReminder::SENT, RestockReminder::firstWhere('phone_key', $first)->status);
        $this->assertSame(RestockReminder::QUEUED, RestockReminder::firstWhere('phone_key', $second)->status);
        $this->assertSame(RestockReminder::SENT, RestockReminder::firstWhere('phone_key', $mikas)->status);
    }

    public function test_nothing_is_sent_while_turned_off_or_outside_sending_hours(): void
    {
        $this->delivered('09170000001', cra: $this->anna);
        $this->artisan('reminders:build');
        RestockReminder::query()->update(['status' => RestockReminder::QUEUED, 'queued_at' => now()]);
        Http::preventStrayRequests();
        Http::fake();

        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00', 'Asia/Manila'));
        $this->artisan('reminders:send');
        $this->turnOn();
        $this->travelTo(CarbonImmutable::parse('2026-11-20 20:30', 'Asia/Manila'));
        $this->artisan('reminders:send');

        Http::assertNothingSent();
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

        $this->assertSame(RestockReminder::SENT, RestockReminder::firstWhere('phone_key', $ticked)->status);
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

    public function test_only_managers_open_templates_and_settings(): void
    {
        $this->actingAs($this->anna)->get(route('sms-reminders.templates.index'))->assertForbidden();
        $this->actingAs($this->anna)->get(route('settings.sms-reminders.index'))->assertForbidden();

        $supervisor = $this->supervisor();
        $this->actingAs($supervisor)->get(route('sms-reminders.templates.index'))->assertOk();
        $this->actingAs($supervisor)->put(route('settings.sms-reminders.update'), ['enabled' => 1, 'send_time' => '10:00', 'daily_cap' => 150])->assertRedirect();

        $this->assertTrue(SmsSettings::enabled());
        $this->assertSame('10:00', SmsSettings::sendTime());
        $this->assertSame(150, SmsSettings::dailyCap());
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
}
