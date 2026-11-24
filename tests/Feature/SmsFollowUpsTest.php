<?php

namespace Tests\Feature;

use App\Models\CustomerClaim;
use App\Models\DeliveredOrder;
use App\Models\Lead;
use App\Models\PancakeOrder;
use App\Models\Product;
use App\Models\RestockReminder;
use App\Models\Role;
use App\Models\Setting;
use App\Models\SmsMessage;
use App\Models\SmsReply;
use App\Models\User;
use App\Services\RestockReminders;
use App\Support\SmsSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmsFollowUpsTest extends TestCase
{
    use RefreshDatabase;

    private User $anna;

    private User $mika;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Real date Fri, Nov 20: reminders for products running out on Fri, Nov 27.
        $this->travelTo(CarbonImmutable::parse('2026-11-20 08:00', 'Asia/Manila'));
        $this->anna = $this->cra('Anna', 'device-1');
        $this->mika = $this->cra('Mika', 'device-2');
        Product::create(['name' => 'Clear Sight 3.0', 'consumption_days' => 15]);
        Product::create(['name' => 'Sinuxyl', 'consumption_days' => 15]);
        Setting::put(SmsSettings::ENABLED, '1');
    }

    private function cra(string $name, string $device): User
    {
        $cra = User::create(['email' => strtolower($name).'@example.com', 'display_name' => $name, 'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true]);
        $cra->forceFill(['call_webhook_url' => "https://trigger.macrodroid.com/{$device}/call", 'sms_confirmed_at' => now(), 'sms_marked_ready_by' => $cra->id])->save();

        return $cra;
    }

    /**
     * 1 pc delivered on $on (15 days each) runs out $on + 14: Nov 13 → Nov 27.
     */
    private function delivered(string $phone, string $on = '2026-11-13', string $product = 'Clear Sight 3.0', ?User $cra = null): string
    {
        $this->seq++;
        DeliveredOrder::create(['order_id' => "ORD-{$this->seq}", 'customer_name' => 'Maria Santos', 'phone_number' => $phone,
            'product_raw' => $product, 'qty' => 1, 'delivered_date' => $on, 'source' => DeliveredOrder::SOURCE_SHECOM]);
        $phoneKey = substr(preg_replace('/\D+/', '', $phone), -10);
        if ($cra) {
            CustomerClaim::firstOrCreate(['phone_key' => $phoneKey], ['user_id' => $cra->id, 'claimed_at' => now()]);
            $this->listLead($phone, $on, $product, 1, $cra);
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

    /** Mark the customer's text of $day as sent at 10 AM that day. */
    private function sent(string $phoneKey, string $day): RestockReminder
    {
        $text = RestockReminder::where('phone_key', $phoneKey)->whereDate('remind_on', $day)->firstOrFail();
        $text->update(['status' => RestockReminder::SENT, 'sent_at' => CarbonImmutable::parse($day.' 10:00', 'Asia/Manila')->utc(), 'message' => 'Hi Maria!']);

        return $text;
    }

    public function test_a_lead_is_texted_once_even_when_it_is_listed_again(): void
    {
        $phoneKey = $this->delivered('09170000001', cra: $this->anna);
        $this->artisan('reminders:build');

        $text = RestockReminder::firstWhere('phone_key', $phoneKey);
        $this->assertStringContainsString('Malapit na maubos ang Clear Sight 3.0 mo (~27 Nov)', app(RestockReminders::class)->messageFor($text));
        $this->actingAs($this->anna)->get(route('sms-reminders.index'))->assertOk()->assertSee('Out of stock Nov 27');

        // The backlog fill moves the untouched lead to a later lead day: already texted, so no second text.
        $this->sent($phoneKey, '2026-11-20');
        Lead::whereKey($text->lead_ids)->update(['listed_on' => '2026-11-22']);
        $this->travelTo(CarbonImmutable::parse('2026-11-22 08:00', 'Asia/Manila'));
        $this->artisan('reminders:build');
        $second = RestockReminder::where('phone_key', $phoneKey)->whereDate('remind_on', '2026-11-22')->sole();
        $this->assertSame([RestockReminder::SKIPPED, 'Already texted on Nov 20'], [$second->status, $second->error]);
    }

    public function test_at_9_pm_a_customer_who_has_not_reordered_gets_a_follow_up_7_days_later_up_to_3(): void
    {
        $waiting = $this->delivered('09170000001', cra: $this->anna);
        $reordered = $this->delivered('09170000002', cra: $this->anna);
        $this->artisan('reminders:build');
        $this->sent($waiting, '2026-11-20');
        $this->sent($reordered, '2026-11-20');
        PancakeOrder::create(['pancake_order_id' => 'P1', 'ordered_on' => '2026-11-20', 'phone_key' => $reordered, 'items' => [['name' => 'Clear Sight 3.0', 'qty' => 1]]]);

        $this->travelTo(CarbonImmutable::parse('2026-11-20 21:00', 'Asia/Manila'));
        $this->artisan('reminders:follow-up')->assertSuccessful();

        $followUp = RestockReminder::where('phone_key', $waiting)->where('kind', RestockReminder::FOLLOW_UP)->sole();
        $this->assertSame(['2026-11-27', 1, RestockReminder::PENDING], [$followUp->remind_on->toDateString(), $followUp->follow_up_number, $followUp->status]);
        $this->assertFalse(RestockReminder::where('phone_key', $reordered)->where('kind', RestockReminder::FOLLOW_UP)->exists());

        // It uses the follow-up text and shows in Today's texts on its day.
        $this->assertStringContainsString('kumusta po ang Clear Sight 3.0 mo', app(RestockReminders::class)->messageFor($followUp));
        $this->travelTo(CarbonImmutable::parse('2026-11-27 08:00', 'Asia/Manila'));
        $this->actingAs($this->anna)->get(route('sms-reminders.index', ['status' => 'follow']))->assertOk()->assertSee('Follow-up 1 of 3');

        // After the 3rd follow-up with no reorder: No response, no 4th.
        $followUp->update(['follow_up_number' => 3, 'status' => RestockReminder::SENT, 'sent_at' => now()]);
        $this->artisan('reminders:follow-up');
        $this->assertNotNull($followUp->fresh()->no_response_at);
        $this->assertSame(1, RestockReminder::where('phone_key', $waiting)->where('kind', RestockReminder::FOLLOW_UP)->count());
        $this->actingAs($this->anna)->get(route('sms-reminders.no-response'))->assertOk()->assertSee('Maria Santos')->assertSee('Call');
    }

    public function test_the_cra_chats_with_the_customer_and_sees_each_text_with_its_no_reorder_reason(): void
    {
        Http::fake(['trigger.macrodroid.com/*' => Http::response('ok')]);
        $this->travelTo(CarbonImmutable::parse('2026-11-20 12:30', 'Asia/Manila'));
        $phoneKey = $this->delivered('09170000001', cra: $this->anna);
        $this->artisan('reminders:build');
        $text = $this->sent($phoneKey, '2026-11-20');
        SmsReply::create(['user_id' => $this->anna->id, 'restock_reminder_id' => $text->id, 'phone_key' => $phoneKey, 'phone_number' => '09170000001',
            'body' => 'May stocks pa po', 'kind' => SmsReply::OTHER, 'received_at' => now()]);

        // Needs reply: the thread shows the text, the reply and the reminder with its reason.
        $this->actingAs($this->anna)->get(route('sms-reminders.conversations.index'))->assertOk()
            ->assertSeeInOrder(['Hi Maria!', 'May stocks pa po'])->assertSee('Reminder:')->assertSee('No reorder reason: May stocks pa po (their reply)')
            ->assertSee('1st follow-up:');
        $this->actingAs($this->mika)->get(route('sms-reminders.conversations.index', ['phone' => $phoneKey]))->assertOk()->assertDontSee('May stocks pa po');

        // Replying sends from Anna's phone, isn't counted in the daily limit and answers the reply.
        $this->actingAs($this->anna)->post(route('sms-reminders.conversations.text', $phoneKey), ['message' => 'Sige po, text lang kayo pag paubos na.'])->assertSessionHasNoErrors();
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'device-1/sms') && $request['message'] === 'Sige po, text lang kayo pag paubos na.');
        $this->assertSame(1, SmsMessage::count());
        // Only the reminder sent this morning counts toward the daily limit, not the typed reply.
        $this->assertSame(1, SmsSettings::sentToday($this->anna->id));
        $this->assertNotNull(SmsReply::first()->handled_at);
        $this->actingAs($this->mika)->post(route('sms-reminders.conversations.text', $phoneKey), ['message' => 'Hi'])->assertForbidden();

        // The CRA's own reason replaces the reply's.
        $this->actingAs($this->anna)->patch(route('sms-reminders.conversations.reason', $text), ['reason' => 'may stocks pa, 2 bottles'])->assertRedirect();
        $this->actingAs($this->anna)->get(route('sms-reminders.conversations.index', ['phone' => $phoneKey, 'show' => 'all']))->assertSee('No reorder reason: may stocks pa, 2 bottles');
    }

    public function test_the_cra_schedules_moves_and_cancels_follow_ups_and_marking_reordered_stops_them(): void
    {
        $phoneKey = $this->delivered('09170000001', cra: $this->anna);
        $this->artisan('reminders:build');
        $this->sent($phoneKey, '2026-11-20');

        $this->actingAs($this->anna)->post(route('sms-reminders.follow-ups.store', $phoneKey), ['day' => '2026-11-23', 'note' => 'call after payday'])->assertSessionHasNoErrors();
        $followUp = RestockReminder::where('phone_key', $phoneKey)->where('kind', RestockReminder::FOLLOW_UP)->sole();
        $this->assertSame([$this->anna->id, 'call after payday'], [$followUp->scheduled_by, $followUp->note]);
        $this->actingAs($this->anna)->get(route('sms-reminders.follow-ups.index'))->assertOk()->assertSee('Scheduled by Anna')->assertSee('call after payday');
        $this->actingAs($this->mika)->post(route('sms-reminders.follow-ups.store', $phoneKey), ['day' => '2026-11-24'])->assertForbidden();

        $this->actingAs($this->anna)->patch(route('sms-reminders.follow-ups.update', $followUp), ['day' => '2026-11-25']);
        $this->assertSame('2026-11-25', $followUp->fresh()->remind_on->toDateString());

        // Marked reordered: the follow-up is cancelled and no more are added.
        $this->actingAs($this->anna)->post(route('sms-reminders.conversations.reordered', $phoneKey))->assertRedirect();
        $this->assertSame([RestockReminder::SKIPPED, 'Reordered'], [$followUp->fresh()->status, $followUp->fresh()->error]);
        $this->travelTo(CarbonImmutable::parse('2026-11-20 21:00', 'Asia/Manila'));
        $this->artisan('reminders:follow-up');
        $this->assertSame(1, RestockReminder::where('phone_key', $phoneKey)->where('kind', RestockReminder::FOLLOW_UP)->count());
    }
}
