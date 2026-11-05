<?php

namespace App\Console\Commands;

use App\Services\AssignedLeadsImporter;
use App\Services\LeadGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ImportAssignedLeads extends Command
{
    protected $signature = 'leads:import-assigned-leads
        {paths* : CSV exports of the old tracker\'s weekly "Assigned Leads" sheets, oldest first (a later file wins for the same order)}
        {--cra=* : Sheet CRA name with its app display name, e.g. --cra="Anna=Joanna Rose". Rows of an unmapped CRA are skipped.}
        {--dry-run : Show what would be saved without saving}';

    protected $description = 'Import the weekly Assigned Leads sheets: orders found by contact # + delivered date + product, listed on the sheet\'s out-of-stock date';

    public function handle(AssignedLeadsImporter $importer): int
    {
        $craIds = $this->craIds();

        if ($craIds === null) {
            return self::FAILURE;
        }

        foreach ($this->argument('paths') as $path) {
            if (! File::exists($path)) {
                $this->error("No file at {$path}.");

                return self::FAILURE;
            }
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->line(($dryRun ? '[dry run] ' : '').'Database: '.DB::connection()->getDatabaseName());

        DB::transaction(function () use ($importer, $craIds, $dryRun) {
            foreach ($this->argument('paths') as $path) {
                $rows = $importer->parse($path);
                $report = $importer->import($rows, $craIds, $dryRun);
                $leads = collect($report['leads']);

                $this->newLine();
                $this->info(sprintf('%s: %d rows → %d new leads, %d existing pool leads filled in, %d skipped, %d without a matching order.',
                    basename($path), count($rows), $report['created'], $report['updated'], count($report['skipped']), count($report['unmatched'])));

                $this->table(['Listed on', ...array_keys($craIds), 'CRD', 'FSD'],
                    $leads->groupBy('listed_on')->sortKeys()->map(fn ($day, $key) => [
                        $key,
                        ...array_map(fn (int $id) => $day->where('assigned_to', $id)->count(), array_values($craIds)),
                        $day->where('lead_type', 'crd')->count(),
                        $day->where('lead_type', 'fsd')->count(),
                    ])->values()->all());

                foreach ([...$report['skipped'], ...$report['unmatched']] as $line) {
                    $this->line("  {$line}");
                }

                foreach (collect($report['warnings'])->countBy(fn (string $w) => preg_replace('/^line \d+ \([^)]*\)/', '', $w)) as $warning => $count) {
                    $this->warn("  {$count}× {$warning}");
                }
            }
        });

        return self::SUCCESS;
    }

    /**
     * @return array<string, int>|null sheet CRA name (lower case) => user id
     */
    private function craIds(): ?array
    {
        $cras = LeadGenerator::cras()->mapWithKeys(fn ($user) => [strtolower($user->displayName()) => $user->id]);
        $ids = [];

        foreach ($this->option('cra') as $pair) {
            [$sheet, $app] = array_map('trim', explode('=', $pair, 2) + [1 => '']);

            if (! isset($cras[strtolower($app)])) {
                $this->error("No active CRA named \"{$app}\" for --cra=\"{$pair}\".");

                return null;
            }

            $ids[strtolower($sheet)] = $cras[strtolower($app)];
        }

        return $ids;
    }
}
