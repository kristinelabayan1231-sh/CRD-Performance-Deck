<?php

namespace App\Console\Commands;

use App\Services\LeadGenerator;
use App\Services\TrackerExportImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ImportTrackerExport extends Command
{
    protected $signature = 'leads:import-tracker-export
        {path : CSV export of a month of the old Segmentation Tracker (Pancake order columns + tracking columns)}
        {--keep=* : Sheet CRA name whose rows stay assigned, with its app display name, e.g. --keep="Anna=Joanna Rose". Every other row goes in the pool.}
        {--dry-run : Show what would be saved without saving}';

    protected $description = 'Import a June–Aug tracker month: kept CRAs keep their rows, the rest go unassigned into the backlog pool';

    public function handle(TrackerExportImporter $importer): int
    {
        $path = $this->argument('path');

        if (! File::exists($path)) {
            $this->error("No file at {$path}.");

            return self::FAILURE;
        }

        $rows = $importer->parse($path);
        $craIds = $this->craIds();

        if ($craIds === null) {
            return self::FAILURE;
        }

        $this->line(($this->option('dry-run') ? '[dry run] ' : '').'Database: '.DB::connection()->getDatabaseName());

        $report = DB::transaction(fn () => $importer->import($rows, $craIds, (bool) $this->option('dry-run')));
        $leads = collect($report['leads']);
        $names = array_flip($craIds);

        $this->info(sprintf('%d rows → %d saved (%d kept by their CRA, %d in the pool), %d skipped as existing leads.',
            count($rows), $report['saved'], $report['kept'], $report['pooled'], count($report['skipped'])));

        $this->table(['Out-of-stock month', 'Pool', ...array_keys($craIds), 'CRD', 'FSD'],
            $leads->groupBy(fn (array $l) => substr($l['est_out_of_stock_date'], 0, 7))->sortKeys()
                ->map(fn ($month, $key) => [
                    $key,
                    $month->whereNull('assigned_to')->count(),
                    ...array_map(fn (int $id) => $month->where('assigned_to', $id)->count(), array_values($craIds)),
                    $month->where('lead_type', 'crd')->count(),
                    $month->where('lead_type', 'fsd')->count(),
                ])->values()->all());

        foreach ($report['skipped'] as $line) {
            $this->line("  skipped: {$line}");
        }

        foreach (collect($report['warnings'])->countBy(fn (string $w) => preg_replace('/^line \d+ \([^)]*\)/', '', $w)) as $warning => $count) {
            $this->warn("  {$count}× {$warning}");
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, int>|null sheet CRA name (lower case) => user id
     */
    private function craIds(): ?array
    {
        $cras = LeadGenerator::cras()->mapWithKeys(fn ($user) => [strtolower($user->displayName()) => $user->id]);
        $ids = [];

        foreach ($this->option('keep') as $pair) {
            [$sheet, $app] = array_map('trim', explode('=', $pair, 2) + [1 => '']);

            if (! isset($cras[strtolower($app)])) {
                $this->error("No active CRA named \"{$app}\" for --keep=\"{$pair}\".");

                return null;
            }

            $ids[strtolower($sheet)] = $cras[strtolower($app)];
        }

        return $ids;
    }
}
