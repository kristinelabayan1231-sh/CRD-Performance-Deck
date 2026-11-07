<?php

namespace App\Console\Commands;

use App\Services\LeadGenerator;
use App\Services\LeadOwnerCorrections;
use Illuminate\Console\Command;

class CorrectLeadOwners extends Command
{
    protected $signature = 'leads:correct-owners
        {--apply : Move the leads. Without it, only reports what would move.}
        {--list : Name every lead that would move.}
        {--lead=* : Lead id to move to their rightful CRA even if it was worked.}';

    protected $description = "Check every CRA's untouched leads against who handled the most orders, and move those that aren't theirs";

    public function handle(LeadOwnerCorrections $corrections): int
    {
        $names = LeadGenerator::cras()->mapWithKeys(fn ($cra) => [$cra->id => $cra->displayName()]);
        ['moves' => $moves, 'held_for_exclusive' => $held] = $corrections->scan(array_map('intval', $this->option('lead')));

        $moves->countBy(fn (array $move) => $names[$move['lead']->assigned_to].' -> '.$names[$move['to']])->sortKeys()
            ->each(fn (int $count, string $route) => $this->line("  {$route}: {$count}"));
        $this->info("Would move {$moves->count()} leads; High AOV / VIP CRA changes with {$moves->whereNotNull('account')->count()} of them.");
        $this->line("Left with their CRA because the rightful CRA is exclusive: {$held}.");

        if ($this->option('list')) {
            foreach ($moves as $move) {
                $this->line("  {$move['lead']->id} | {$move['lead']->customer_name} | {$names[$move['lead']->assigned_to]} -> {$names[$move['to']]}");
            }
        }

        if ($this->option('apply')) {
            $this->info('Moved '.$corrections->apply($moves).' leads.');
        } else {
            $this->comment('Nothing was moved. Run again with --apply to move them.');
        }

        return self::SUCCESS;
    }
}
