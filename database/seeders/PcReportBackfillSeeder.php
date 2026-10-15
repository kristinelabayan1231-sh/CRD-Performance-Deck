<?php

namespace Database\Seeders;

use App\Models\PcReportEntry;
use App\Models\PcReportRow;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * PC Report: Total Inquiries and Total Engagement for Oct 1–15, 2026, from the CRAs' Google Sheets
 * (data/pc-report-october-2026.json). Safe to run again: only days still empty are filled, so a
 * number typed in the app is never replaced. A page with Dormant / Active numbers gets its split
 * rows (usual months) when the CRA doesn't have them yet. CRAs are matched by display name.
 *
 * php artisan db:seed --class=PcReportBackfillSeeder --force
 */
class PcReportBackfillSeeder extends Seeder
{
    public const DATA = __DIR__.'/data/pc-report-october-2026.json';

    public function run(): void
    {
        $rows = collect(json_decode(file_get_contents(self::DATA), true));
        $users = User::whereIn('display_name', $rows->pluck('cra')->unique())->pluck('id', 'display_name');

        $missing = $rows->pluck('cra')->unique()->diff($users->keys());
        if ($missing->isNotEmpty()) {
            $this->command?->warn('No user for: '.$missing->implode(', ').' (their rows are left out).');
        }

        $rows = $rows->filter(fn (array $row) => $users->has($row['cra']));
        $this->splitRows($rows, $users);

        $existing = PcReportEntry::whereIn('user_id', $users->values())->whereDate('date', '>=', $rows->min('date'))->whereDate('date', '<=', $rows->max('date'))->get()
            ->map(fn (PcReportEntry $entry) => $entry->user_id.'|'.$entry->page.'|'.$entry->category.'|'.$entry->date->toDateString())
            ->flip();

        $created = 0;
        foreach ($rows as $row) {
            $userId = $users[$row['cra']];
            if ($existing->has($userId.'|'.$row['page'].'|'.$row['category'].'|'.$row['date'])) {
                continue;
            }

            PcReportEntry::create([
                'user_id' => $userId, 'page' => $row['page'], 'category' => $row['category'], 'date' => $row['date'],
                'inquiries' => $row['inquiries'], 'engagements' => $row['engagements'],
            ]);
            $created++;
        }

        $this->command?->info("PC Report: {$created} days filled, ".($rows->count() - $created).' already had numbers.');
    }

    /**
     * @param  Collection<int, array{cra: string, page: string, category: string}>  $rows
     * @param  Collection<string, int>  $users
     */
    private function splitRows(Collection $rows, Collection $users): void
    {
        $rows->filter(fn (array $row) => $row['category'] !== '')
            ->map(fn (array $row) => [$users[$row['cra']], $row['page']])
            ->unique(fn (array $pair) => implode('|', $pair))
            ->each(function (array $pair) {
                [$userId, $page] = $pair;
                foreach (PcReportRow::SPLIT as $category => [$from, $to]) {
                    PcReportRow::firstOrCreate(
                        ['user_id' => $userId, 'page' => $page, 'category' => $category],
                        ['from_month' => $from.'-01', 'to_month' => $to ? $to.'-01' : null],
                    );
                }
            });
    }
}
