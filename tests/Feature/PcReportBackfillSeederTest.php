<?php

namespace Tests\Feature;

use App\Models\PcReportEntry;
use App\Models\PcReportRow;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PcReportBackfillSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PcReportBackfillSeederTest extends TestCase
{
    use RefreshDatabase;

    private function cra(string $name): User
    {
        return User::create([
            'email' => str_replace([' ', '-'], '', strtolower($name)).'@example.com', 'display_name' => $name,
            'role_id' => Role::firstWhere('slug', Role::CRA)->id, 'is_active' => true,
        ]);
    }

    public function test_it_fills_empty_days_adds_split_rows_and_keeps_typed_numbers(): void
    {
        $regina = $this->cra('Regina');
        $rows = collect(json_decode(file_get_contents(PcReportBackfillSeeder::DATA), true))->where('cra', 'Regina');
        // Typed in the app before the backfill ran: kept as it is.
        PcReportEntry::create(['user_id' => $regina->id, 'page' => 'Clear Sight Hub', 'category' => 'DORMANT', 'date' => '2026-10-10', 'inquiries' => 1, 'engagements' => 2]);

        $this->seed(PcReportBackfillSeeder::class);
        $this->seed(PcReportBackfillSeeder::class);

        $this->assertSame($rows->count(), PcReportEntry::count());
        $this->assertDatabaseHas('pc_report_entries', ['user_id' => $regina->id, 'page' => 'Clear Sight Hub', 'category' => 'ACTIVE', 'inquiries' => 3709, 'engagements' => 2399]);
        $this->assertDatabaseHas('pc_report_entries', ['user_id' => $regina->id, 'page' => 'Clear Sight Hub', 'category' => 'DORMANT', 'inquiries' => 1, 'engagements' => 2]);
        $this->assertEqualsCanonicalizing(['DORMANT', 'ACTIVE'], PcReportRow::where(['user_id' => $regina->id, 'page' => 'Clear Sight Hub'])->pluck('category')->all());
    }

    public function test_it_sets_each_days_creation_update_time_and_keeps_months_already_picked(): void
    {
        $regina = $this->cra('Regina');
        $lhea = $this->cra('Lhea');
        // Picked by a Supervisor before the backfill ran: kept.
        PcReportEntry::create(['user_id' => $regina->id, 'page' => 'Clear Sight Hub', 'category' => 'ACTIVE', 'date' => '2026-10-04', 'from_month' => '2026-03-01', 'to_month' => '2026-03-01']);

        $this->seed(PcReportBackfillSeeder::class);

        $time = fn (User $cra, string $page, string $category, string $date) => PcReportEntry::where(['user_id' => $cra->id, 'page' => $page, 'category' => $category])
            ->whereDate('date', $date)->first(['from_month', 'to_month'])?->only(['from_month', 'to_month']);

        // "July, Feb 2026" on a day with numbers: February to July 2026.
        $this->assertEquals(['from_month' => now()->parse('2026-02-01'), 'to_month' => now()->parse('2026-07-01')], $time($regina, 'Clear Sight Hub', 'ACTIVE', '2026-10-01'));
        $this->assertEquals(['from_month' => now()->parse('2026-03-01'), 'to_month' => now()->parse('2026-03-01')], $time($regina, 'Clear Sight Hub', 'ACTIVE', '2026-10-04'));
        // A day with a time but no numbers in the sheet.
        $this->assertEquals(['from_month' => now()->parse('2025-02-01'), 'to_month' => now()->parse('2025-06-01')], $time($lhea, 'Audicure Ear Drops', '', '2026-10-08'));
    }
}
