<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Services\LeadGenerator;
use App\Services\MissingLeads;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time: make the leads the app never made (MissingLeads), each on its own out-of-stock day as untouched backlog of
 * the customer's own CRA, or unassigned in the pool. A dry run unless --apply.
 */
class CreateMissingLeads extends Command
{
    protected $signature = 'leads:create-missing {--apply : Create the leads (without it, only counts them)}';

    protected $description = "Make the leads of customers whose last delivery ran out of stock without one: their CRA's backlog, else the pool";

    public function handle(MissingLeads $missing): int
    {
        $apply = (bool) $this->option('apply');
        $names = LeadGenerator::cras()->mapWithKeys(fn ($cra) => [$cra->id => $cra->displayName()]);
        $tally = [];
        $created = 0;

        $missing->scan(Lead::today(), function (array $rows) use ($apply, &$tally, &$created) {
            foreach ($rows as $row) {
                $who = $row['assigned_to'] ?? 'pool';
                $tally[$who][$row['lead_type']] = ($tally[$who][$row['lead_type']] ?? 0) + 1;
            }

            if (! $apply) {
                return;
            }

            $now = now();
            DB::transaction(function () use ($rows, $now, &$created) {
                // An order that got a lead meanwhile is left alone.
                $taken = Lead::whereIn('order_id', array_column($rows, 'order_id'))->pluck('order_id')->flip();

                foreach (array_chunk(array_values($rows), 500) as $part) {
                    $insert = array_values(array_filter(array_map(fn (array $row) => isset($taken[$row['order_id']]) ? null : [
                        ...$row,
                        'listed_on' => $row['est_out_of_stock_date'],
                        'assigned_at' => $row['assigned_to'] !== null ? $now : null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], $part)));
                    $created += $insert === [] ? 0 : Lead::insertOrIgnore($insert);
                }
            });
        });

        $rows = collect($tally)->map(fn (array $types, int|string $who) => [
            $who === 'pool' ? 'Pool (no CRA)' : ($names[$who] ?? "CRA #{$who}"),
            number_format($types[Lead::TYPE_CRD] ?? 0),
            number_format($types[Lead::TYPE_FSD] ?? 0),
            number_format(array_sum($types)),
        ])->sortBy(fn (array $row, int|string $who) => [$who === 'pool' ? 1 : 0, -array_sum($tally[$who])])->values();

        $this->table(['Goes to', 'CRD', 'FSD', 'Total'], $rows->all());
        $total = array_sum(array_map('array_sum', $tally));
        $this->info($apply ? "Created {$created} of {$total} missing leads." : "{$total} missing leads. Dry run: nothing created (add --apply).");

        return self::SUCCESS;
    }
}
