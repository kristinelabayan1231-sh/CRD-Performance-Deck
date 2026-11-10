<?php

namespace App\Console\Commands;

use App\Services\McPayroll;
use Illuminate\Console\Command;

class SnapshotMonthlyCost extends Command
{
    protected $signature = 'mc:snapshot';

    protected $description = 'Save CRD - MC as this month\'s payroll for the MC / salaries API';

    public function handle(McPayroll $payroll): int
    {
        $snapshot = $payroll->record();
        $this->info("CRD - MC saved for {$snapshot->month}.");

        return self::SUCCESS;
    }
}
