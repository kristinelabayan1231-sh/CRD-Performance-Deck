<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Role;
use App\Models\User;
use App\Support\SegmentationOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SegmentationOptionsTest extends TestCase
{
    use RefreshDatabase;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supervisor = User::create(['email' => 'sup@example.com', 'role_id' => Role::firstWhere('slug', Role::CRA_SUPERVISOR)->id, 'is_active' => true]);
    }

    private function lead(array $fields = []): Lead
    {
        return Lead::create([
            'order_id' => (string) fake()->unique()->randomNumber(6), 'customer_name' => 'Ana', 'phone_number' => '9171111111', 'product_name' => 'Sinuxyl', 'qty' => 1,
            'delivered_date' => '2026-09-20', 'consumption_days' => 15, 'est_out_of_stock_date' => '2026-10-04', 'lead_type' => Lead::TYPE_FSD,
            ...$fields,
        ]);
    }

    /**
     * Every current feedback choice as form rows, with $changes merged into the row for that key.
     */
    private function feedbackRows(array $changes = [], array $extra = []): array
    {
        $colors = array_flip(config('segmentation.option_colors'));

        return [...collect(config('segmentation.feedback'))->map(fn (array $option, string $key) => [
            'key' => $key, 'label' => $option[0], 'color' => $colors[$option[1]], ...($changes[$key] ?? []),
        ])->values(), ...$extra];
    }

    public function test_supervisor_renames_recolours_adds_and_removes_choices(): void
    {
        $this->actingAs($this->supervisor)->get(route('settings.segmentation-options.index'))
            ->assertOk()->assertSee("Customer's Feedback")->assertSee('NO BUDGET');

        $this->put(route('settings.segmentation-options.update', 'feedback'), ['options' => $this->feedbackRows(
            ['no_budget' => ['label' => 'NO BUDGET YET', 'color' => 'Red'], 'ineffective' => ['remove' => 1]],
            [['label' => 'Wrong number', 'color' => 'Charcoal'], ['label' => '']],
        )])->assertRedirect(route('settings.segmentation-options.index'));

        // Saved choices come back on the next boot.
        config(['segmentation.feedback' => []]);
        SegmentationOptions::apply();
        $feedback = config('segmentation.feedback');

        $this->assertSame(['NO BUDGET YET', 'bg-[#b10202] text-[#ffcfc9]'], $feedback['no_budget']);
        $this->assertArrayNotHasKey('ineffective', $feedback);
        $this->assertSame(['Wrong number', 'bg-[#3d3d3d] text-white'], $feedback['wrong_number']);
        $this->assertSame('wrong_number', array_key_last($feedback));

        // The tracker accepts the new choice.
        $lead = $this->lead();
        $this->actingAs(User::create(['email' => 'boss@example.com', 'role_id' => Role::superAdmin()->id, 'is_active' => true]))
            ->patch(route('segmentation.update', $lead), ['feedback' => 'wrong_number'])->assertSessionHasNoErrors();
        $this->assertSame('wrong_number', $lead->fresh()->feedback);
    }

    public function test_choices_used_by_leads_or_rules_cannot_be_removed(): void
    {
        $this->lead(['feedback' => 'no_budget']);

        $this->actingAs($this->supervisor)
            ->put(route('settings.segmentation-options.update', 'feedback'), ['options' => $this->feedbackRows(['no_budget' => ['remove' => 1]])])
            ->assertSessionHasErrorsIn('options_feedback', ['options' => '“NO BUDGET” can\'t be removed: 1 lead still use it.']);

        $this->put(route('settings.segmentation-options.update', 'feedback'), ['options' => $this->feedbackRows(['purchased' => ['remove' => 1]])])
            ->assertSessionHasErrorsIn('options_feedback', 'options');

        $this->assertArrayHasKey('purchased', config('segmentation.feedback'));
        $this->assertArrayHasKey('no_budget', config('segmentation.feedback'));
    }

    public function test_cras_cannot_edit_choices(): void
    {
        $cra = User::create(['email' => 'cra@example.com', 'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true]);

        $this->actingAs($cra)->get(route('settings.segmentation-options.index'))->assertForbidden();
        $this->actingAs($cra)->put(route('settings.segmentation-options.update', 'feedback'), ['options' => $this->feedbackRows()])->assertForbidden();
    }
}
