<?php

namespace Database\Seeders;

use App\Models\PcReportEntry;
use App\Models\PcReportRow;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * PC Report: Total Inquiries and Total Engagement (data/pc-report-october-2026.json) and the Creation/Update Time
 * (data/pc-report-october-2026-times.json) for Oct 1–15, 2026, from the CRAs' Google Sheets. Safe to run again:
 * only what is still empty is filled, so numbers typed and months picked in the app are never replaced. A page with
 * Dormant / Active rows gets its split rows (usual months) when the CRA doesn't have them yet. CRAs are matched by
 * display name. A sheet time naming separate months ("May 2025, Aug 2026") is saved as its first to last month.
 *
 * php artisan db:seed --class=PcReportBackfillSeeder --force
 */
class PcReportBackfillSeeder extends Seeder
{
    public const DATA = __DIR__.'/data/pc-report-october-2026.json';

    public const TIMES = __DIR__.'/data/pc-report-october-2026-times.json';

    public function run(): void
    {
        $rows = collect(json_decode(file_get_contents(self::DATA), true));
        $times = collect(json_decode(file_get_contents(self::TIMES), true));
        $cras = $rows->pluck('cra')->merge($times->pluck('cra'))->unique();
        $users = User::whereIn('display_name', $cras)->pluck('id', 'display_name');

        $missing = $cras->diff($users->keys());
        if ($missing->isNotEmpty()) {
            $this->command?->warn('No user for: '.$missing->implode(', ').' (their rows are left out).');
        }

        $rows = $rows->filter(fn (array $row) => $users->has($row['cra']));
        $times = $times->filter(fn (array $row) => $users->has($row['cra']));
        $this->splitRows($rows->merge($times), $users);

        $existing = $this->entries($users, $rows->merge($times));

        $created = 0;
        foreach ($rows as $row) {
            $key = $users[$row['cra']].'|'.$row['page'].'|'.$row['category'].'|'.$row['date'];
            if ($existing->has($key)) {
                continue;
            }

            $existing[$key] = PcReportEntry::create([
                'user_id' => $users[$row['cra']], 'page' => $row['page'], 'category' => $row['category'], 'date' => $row['date'],
                'inquiries' => $row['inquiries'], 'engagements' => $row['engagements'],
            ]);
            $created++;
        }

        $timed = 0;
        foreach ($times as $row) {
            $key = $users[$row['cra']].'|'.$row['page'].'|'.$row['category'].'|'.$row['date'];
            $entry = $existing[$key] ?? new PcReportEntry([
                'user_id' => $users[$row['cra']], 'page' => $row['page'], 'category' => $row['category'], 'date' => $row['date'],
            ]);
            if ($entry->from_month !== null) {
                continue;
            }

            $entry->fill(['from_month' => $row['from'].'-01', 'to_month' => $row['to'].'-01'])->save();
            $existing[$key] = $entry;
            $timed++;
        }

        $this->command?->info("PC Report: {$created} days of numbers filled, ".($rows->count() - $created)." already had them; {$timed} Creation/Update Times set, ".($times->count() - $timed).' already set.');
    }

    /**
     * The CRAs' entries already saved for the backfilled days, keyed "user|page|category|date".
     *
     * @param  Collection<string, int>  $users
     * @param  Collection<int, array{date: string}>  $rows
     * @return Collection<string, PcReportEntry>
     */
    private function entries(Collection $users, Collection $rows): Collection
    {
        return PcReportEntry::whereIn('user_id', $users->values())
            ->whereDate('date', '>=', $rows->min('date'))->whereDate('date', '<=', $rows->max('date'))->get()
            ->keyBy(fn (PcReportEntry $entry) => $entry->user_id.'|'.$entry->page.'|'.$entry->category.'|'.$entry->date->toDateString());
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
