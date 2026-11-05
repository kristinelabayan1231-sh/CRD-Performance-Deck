<?php

namespace App\Console\Commands;

use App\Models\ShecomProductCost;
use App\Services\ShecomClient;
use Illuminate\Console\Command;

class SyncShecomProductCosts extends Command
{
    protected $signature = 'shecom:product-costs';

    protected $description = "Save Shecom's product costs (the Daily I.S. Product Cost)";

    public function handle(ShecomClient $shecom): int
    {
        $rows = $shecom->productCosts();
        $saved = ShecomProductCost::remember($rows);
        $this->info("Product costs saved: {$saved} of ".count($rows).' items have a cost in Shecom.');

        return self::SUCCESS;
    }
}
