<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Role;
use App\Models\Spiel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SpielsTest extends TestCase
{
    use RefreshDatabase;

    private User $lhea;

    private User $regina;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-09 10:00', 'Asia/Manila'));
        $this->lhea = $this->cra('Lhea');
        $this->regina = $this->cra('Regina');
    }

    private function cra(string $name): User
    {
        return User::create([
            'email' => strtolower($name).'@example.com', 'display_name' => $name,
            'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true,
        ]);
    }

    public function test_a_cra_adds_edits_and_deletes_their_spiels_in_settings(): void
    {
        $this->actingAs($this->lhea)->post(route('settings.spiels.store'), ['title' => 'Reorder offer', 'body' => 'Hi {name}! Reorder na po ng {product}?'])
            ->assertSessionHas('status', 'Spiel "Reorder offer" saved.');
        $this->actingAs($this->lhea)->post(route('settings.spiels.store'), ['title' => 'Thank you', 'body' => 'Salamat po, {name}!']);
        [$offer, $thanks] = $this->lhea->spiels()->get()->all();
        $this->assertSame([1, 2], [$offer->position, $thanks->position]);

        $this->actingAs($this->lhea)->put(route('settings.spiels.update', $offer), ['title' => 'Reorder', 'body' => 'Hi {name}!'])
            ->assertSessionHas('status', 'Spiel "Reorder" saved.');
        $this->assertSame(['Reorder', 'Hi {name}!'], [$offer->fresh()->title, $offer->fresh()->body]);

        $this->actingAs($this->lhea)->delete(route('settings.spiels.destroy', $thanks))->assertSessionHas('status', 'Spiel "Thank you" deleted.');
        $this->assertModelMissing($thanks);
    }

    public function test_rich_text_keeps_its_formatting_and_drops_anything_else(): void
    {
        $this->actingAs($this->lhea)->post(route('settings.spiels.store'), [
            'title' => 'Promo',
            'body' => '<p style="color:red" onclick="x()">Hi <b>{name}</b>! <a href="https://evil.test">click</a><script>alert(1)</script></p><ul><li><i>Buy 2</i></li></ul><img src=x onerror=alert(1)>',
        ]);

        $this->assertSame('<p>Hi <b>{name}</b>! click</p><ul><li><i>Buy 2</i></li></ul>', Spiel::sole()->body);
    }

    public function test_formatting_without_any_text_is_not_a_spiel(): void
    {
        $this->actingAs($this->lhea)->post(route('settings.spiels.store'), ['title' => 'Blank', 'body' => '<p><b>&nbsp;</b></p><br>'])
            ->assertSessionHasErrors(['body' => "Write the spiel's content."]);

        $this->assertSame(0, Spiel::count());
    }

    public function test_a_spiel_needs_a_title(): void
    {
        $this->actingAs($this->lhea)->post(route('settings.spiels.store'), ['title' => '', 'body' => 'Hi'])
            ->assertSessionHasErrors(['title' => 'Give the spiel a title.']);

        $this->assertSame(0, Spiel::count());
    }

    public function test_dragging_saves_the_order_of_own_spiels_only(): void
    {
        $first = Spiel::factory()->create(['user_id' => $this->lhea->id, 'position' => 1]);
        $second = Spiel::factory()->create(['user_id' => $this->lhea->id, 'position' => 2]);
        $others = Spiel::factory()->create(['user_id' => $this->regina->id, 'position' => 5]);

        $this->actingAs($this->lhea)->putJson(route('settings.spiels.reorder'), ['ids' => [$others->id, $second->id, $first->id]])->assertOk();

        $this->assertSame([$second->id, $first->id], $this->lhea->spiels()->pluck('id')->all());
        $this->assertSame(5, $others->fresh()->position);
    }

    public function test_nobody_changes_or_deletes_another_users_spiel(): void
    {
        $spiel = Spiel::factory()->create(['user_id' => $this->regina->id, 'title' => 'Mine']);

        $this->actingAs($this->lhea)->put(route('settings.spiels.update', $spiel), ['title' => 'Taken', 'body' => 'x'])->assertForbidden();
        $this->actingAs($this->lhea)->delete(route('settings.spiels.destroy', $spiel))->assertForbidden();

        $this->assertSame('Mine', $spiel->fresh()->title);
    }

    public function test_a_cras_settings_open_on_my_spiels_with_only_their_own(): void
    {
        Spiel::factory()->create(['user_id' => $this->lhea->id, 'title' => 'Lhea greeting']);
        Spiel::factory()->create(['user_id' => $this->regina->id, 'title' => 'Regina greeting']);

        $this->actingAs($this->lhea)->get(route('settings.index'))->assertRedirect(route('settings.spiels.index'));
        $this->actingAs($this->lhea)->get(route('settings.spiels.index'))
            ->assertSeeText('My Spiels')
            ->assertSeeText('Lhea greeting')
            ->assertDontSeeText('Regina greeting')
            ->assertDontSeeText('Product Consumption');
    }

    public function test_tracker_rows_open_the_chat_and_the_spiels_panel_has_only_own_spiels(): void
    {
        Http::fake(['*' => Http::response(['count' => 0, 'stock_outs' => []])]);
        Spiel::factory()->create(['user_id' => $this->lhea->id, 'title' => 'Lhea greeting']);
        Spiel::factory()->create(['user_id' => $this->regina->id, 'title' => 'Regina greeting']);
        $lead = Lead::create([
            'order_id' => '1359294', 'customer_name' => 'Ana Reyes', 'phone_number' => '09171234567', 'product_name' => 'Pterygium Drops', 'qty' => 1,
            'delivered_date' => '2026-09-01', 'consumption_days' => 30, 'est_out_of_stock_date' => '2026-10-09', 'lead_type' => Lead::TYPE_CRD, 'assigned_to' => $this->lhea->id,
        ]);

        $this->actingAs($this->lhea)->get(route('segmentation.index'))
            ->assertSee('data-chat-url="'.route('segmentation.chat', $lead).'"', false)
            ->assertSee('data-spiel-product="Pterygium Drops"', false)
            ->assertSee('Lhea greeting')
            ->assertDontSee('Regina greeting');
    }
}
