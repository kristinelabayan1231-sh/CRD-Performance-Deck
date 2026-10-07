<?php

namespace App\Console\Commands;

use App\Services\LeadGenerator;
use App\Services\ShecomClient;
use App\Services\SheetLeadImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

class ImportSheetLeads extends Command
{
    protected $signature = 'leads:import-sheet
        {path : CSV export of the Segmentation Tracker sheet}
        {--year= : Year of the sheet dates. Defaults to the current year.}
        {--date= : Only this lead day (Est. Out of Stock, YYYY-MM-DD)}
        {--cra= : Only this sheet CRA name}
        {--alias=* : Sheet CRA name to app display name, e.g. --alias="Anna=Joanna Rose"}
        {--orders= : Saved retention report JSON to match against instead of calling the API}
        {--dry-run : Show what would be saved without saving}';

    protected $description = 'Import sheet leads (CRA, CRD/FSD type, tracking fields) and match them to Shecom delivered orders';

    public function handle(SheetLeadImporter $importer, ShecomClient $client): int
    {
        $path = $this->argument('path');

        if (! File::exists($path)) {
            $this->error("No file at {$path}.");

            return self::FAILURE;
        }

        $rows = $importer->parse($path, (int) ($this->option('year') ?: now()->year));
        $batch = SheetLeadImporter::batch($rows, $this->option('date'), $this->option('cra'));

        if ($batch->isEmpty()) {
            $this->warn('No sheet rows for that date/CRA.');

            return self::SUCCESS;
        }

        try {
            $importer->useOrders($this->orders($client));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $craIds = $this->craIds($batch->pluck('cra')->unique()->all());
        $this->line(($this->option('dry-run') ? '[dry run] ' : '').'Database: '.DB::connection()->getDatabaseName());

        foreach ($batch->groupBy(fn (array $r) => $r['est_out_of_stock_date']->toDateString()) as $date => $dayRows) {
            foreach ($dayRows->groupBy('cra') as $cra => $craRows) {
                $report = DB::transaction(fn () => $importer->import($craRows->all(), $craIds, (bool) $this->option('dry-run')));
                $types = $craRows->countBy('lead_type');

                $this->info(sprintf('%s · %s: %d rows (%d CRD, %d FSD) → %d saved (%d new), %d matched to Shecom, %d without an order.',
                    $date, $cra, $craRows->count(), $types['crd'] ?? 0, $types['fsd'] ?? 0,
                    $report['saved'], $report['created'], $report['matched'], count($report['unmatched'])));

                foreach ($report['unmatched'] as $line) {
                    $this->line("  no Shecom order: {$line}");
                }

                foreach ($report['warnings'] as $line) {
                    $this->warn("  {$line}");
                }
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array{crd: list<array<string, mixed>>, fsd: list<array<string, mixed>>}
     */
    private function orders(ShecomClient $client): array
    {
        if (! $this->option('orders')) {
            return $client->deliveredOrders();
        }

        ini_set('memory_limit', config('services.shecom.memory_limit'));

        return ShecomClient::splitDelivered(json_decode(File::get($this->option('orders')), true) ?: []);
    }

    /**
     * Sheet CRA name (lower case) => user id, by --alias or by display name.
     *
     * @param  list<string>  $names
     * @return array<string, int>
     */
    private function craIds(array $names): array
    {
        $aliases = collect($this->option('alias'))
            ->mapWithKeys(function (string $pair) {
                [$sheet, $app] = array_map('trim', explode('=', $pair, 2) + [1 => '']);

                return [strtolower($sheet) => strtolower($app)];
            });

        $cras = LeadGenerator::cras()->mapWithKeys(fn ($user) => [strtolower($user->displayName()) => $user->id]);

        return collect($names)
            ->mapWithKeys(fn (string $name) => [strtolower($name) => $cras[$aliases[strtolower($name)] ?? strtolower($name)] ?? null])
            ->filter()
            ->all();
    }
}
