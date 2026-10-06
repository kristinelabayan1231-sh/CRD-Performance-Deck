<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Services\LeadGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class GenerateLeads extends Command
{
    protected $signature = 'leads:generate {--date= : Lead day (YYYY-MM-DD). Defaults to today in the segmentation timezone.}';

    protected $description = 'Pull customers running out of stock on a day from the retention API and assign them to CRAs';

    public function handle(LeadGenerator $generator): int
    {
        $date = $this->option('date')
            ? CarbonImmutable::parse($this->option('date'))
            : Lead::today();

        try {
            $result = $generator->generate($date);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Leads for {$date->toDateString()}: {$result['found']} found ({$result['created']} new), "
            ."{$result['crd']} CRD / {$result['new']} New, {$result['assigned']} assigned now, {$result['unassigned']} unassigned.");

        return self::SUCCESS;
    }
}
