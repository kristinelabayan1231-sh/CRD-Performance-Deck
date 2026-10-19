<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\PancakeEngagement;
use App\Support\PancakeAccounts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Imports a month of the old Segmentation Tracker (June–Aug 2026) from its CSV export: the Pancake order
 * columns plus the CRA's tracking columns. Rows are filed on their out-of-stock date (delivered + qty ×
 * consumption days − 1). Rows whose CRA is kept stay assigned; every other row
 * goes in the backlog pool unassigned, with its tracking data kept.
 */
class TrackerExportImporter
{
    /** Days per unit for a product with none in Settings → Product Consumption. */
    public const DEFAULT_CONSUMPTION_DAYS = 15;

    /** The sheet's default status: a row with only this and no contact date or note was never worked. */
    private const DEFAULT_STATUS = 'active';

    private ProductCatalog $catalog;

    public function __construct()
    {
        $this->catalog = new ProductCatalog;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function parse(string $path): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Cannot open {$path}.");
        }

        fgetcsv($handle, escape: '');
        // CRD = sold by a CRD team account (Settings → Pancake Accounts) or a CRA's own Pancake account.
        $crd = array_flip([...PancakeAccounts::crdKeys(), ...PancakeAccounts::craUsers()->pluck('pancake_name')->map(PancakeEngagement::staffKey(...))->all()]);
        $rows = [];
        $line = 1;

        while (($cells = fgetcsv($handle, escape: '')) !== false) {
            $line++;
            $cells = array_map(fn ($cell) => trim((string) $cell), array_pad($cells, 21, ''));

            if ($cells[0] === '') {
                continue;
            }

            $callback = $cells[19];
            $recording = $cells[20];

            // Some rows have the recording link in the Callback column.
            if (Str::startsWith($callback, 'http') && $recording === '') {
                [$callback, $recording] = ['', $callback];
            }

            $rows[] = [
                'line' => $line,
                'order_id' => $cells[0],
                'lead_type' => isset($crd[PancakeEngagement::staffKey($cells[1])]) ? Lead::TYPE_CRD : Lead::TYPE_FSD,
                'customer_name' => $cells[2],
                'phone_number' => self::phone($cells[3]),
                'variation' => $cells[4],
                'product' => $cells[5],
                'qty' => preg_match('/^\s*(\d+)/', $cells[4], $m) ? max(1, (int) $m[1]) : 1,
                'delivered_date' => CarbonImmutable::createFromFormat('!H:i d/m/Y', $cells[6])->startOfDay(),
                'cra' => $cells[13],
                'status' => $cells[14],
                'customer_tag' => $cells[15],
                'contact_date' => $cells[16],
                'contact_time' => $cells[17],
                'notes' => $cells[18],
                'callback_date' => $callback,
                'call_recording_url' => $recording,
            ];
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Save $rows as leads. $craIds maps the sheet CRA names (lower case) that keep their rows to user ids;
     * every other row goes in the pool. Orders that are already leads are skipped and listed.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, int>  $craIds
     * @return array{saved: int, kept: int, pooled: int, skipped: list<string>, warnings: list<string>, leads: list<array<string, mixed>>}
     */
    public function import(array $rows, array $craIds, bool $dryRun = false): array
    {
        $report = ['saved' => 0, 'kept' => 0, 'pooled' => 0, 'skipped' => [], 'warnings' => [], 'leads' => []];

        // Merged with + so numeric order IDs stay keys (flatMap would renumber them).
        $existing = [];
        foreach (array_chunk(array_column($rows, 'order_id'), 500) as $ids) {
            $existing += Lead::whereIn('order_id', $ids)->pluck('est_out_of_stock_date', 'order_id')->all();
        }

        foreach ($rows as $row) {
            $where = "line {$row['line']} (order {$row['order_id']}, {$row['customer_name']})";

            if (isset($existing[$row['order_id']])) {
                $report['skipped'][] = "{$where}: already a lead for ".CarbonImmutable::parse($existing[$row['order_id']])->toDateString().'.';

                continue;
            }

            $craId = $craIds[strtolower($row['cra'])] ?? null;
            $attributes = $this->attributes($row, $craId, $report['warnings'], $where);
            $report[$craId ? 'kept' : 'pooled']++;
            $report['leads'][] = $attributes;

            if (! $dryRun) {
                Lead::create($attributes);
            }

            $report['saved']++;
        }

        return $report;
    }

    /**
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    private function attributes(array $row, ?int $craId, array &$warnings, string $where): array
    {
        $product = $this->catalog->match($row['product']);
        $days = (int) $product?->consumption_days ?: self::DEFAULT_CONSUMPTION_DAYS;

        if (! $product?->consumption_days) {
            $warnings[] = "{$where}: no consumption days for \"{$row['product']}\", used ".self::DEFAULT_CONSUMPTION_DAYS.'.';
        }

        $est = $row['delivered_date']->addDays($row['qty'] * $days - 1);
        $base = [
            'order_id' => $row['order_id'],
            'customer_name' => $row['customer_name'],
            'phone_number' => $row['phone_number'],
            'product_name' => $product?->name ?? $row['product'],
            'product_raw' => $row['variation'],
            'qty' => $row['qty'],
            'delivered_date' => $row['delivered_date']->toDateString(),
            'consumption_days' => $days,
            'est_out_of_stock_date' => $est->toDateString(),
            'lead_type' => $row['lead_type'],
            'customer_tag' => $this->optionKey('customer_tags', $row['customer_tag'], $warnings, "{$where} Customer Tagging"),
            'call_recording_url' => $row['call_recording_url'] ?: null,
        ];

        $contactDate = $this->date($row['contact_date'], $warnings, "{$where} Date of Contact");
        $notes = $row['notes'] !== '' ? $row['notes'] : null;

        $status = $this->optionKey('statuses', $row['status'], $warnings, "{$where} Status");
        $worked = $contactDate || $notes || ($status && $status !== self::DEFAULT_STATUS);
        $updatedAt = CarbonImmutable::parse(($contactDate ?? $est)->toDateString().' 12:00', config('segmentation.timezone'))->utc();

        // Pool rows keep the sheet's tracking data; only the CRA is left blank for the daily fill to assign.
        return $base + [
            'assigned_to' => $craId,
            'assigned_at' => $craId ? CarbonImmutable::parse($est->toDateString(), config('segmentation.timezone'))->utc() : null,
            'status' => $worked ? $status : null,
            'status_updated_by' => $worked && $status ? $craId : null,
            'status_updated_at' => $worked && $status ? $updatedAt : null,
            'contact_date' => $contactDate?->toDateString(),
            'contact_time' => $worked ? $this->contactTime($row['contact_time'], $warnings, $where) : null,
            'callback_date' => $this->date($row['callback_date'], $warnings, "{$where} Callback date")?->toDateString(),
            'notes' => $notes,
            'notes_updated_by' => $notes ? $craId : null,
            'notes_updated_at' => $notes ? $updatedAt : null,
        ];
    }

    /**
     * 639xx… becomes 09xx…; other numbers are kept as the sheet has them.
     */
    public static function phone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);

        return strlen($digits) === 12 && str_starts_with($digits, '63') ? '0'.substr($digits, 2) : $phone;
    }

    /**
     * @param  list<string>  $warnings
     */
    public function optionKey(string $list, string $label, array &$warnings, string $where): ?string
    {
        if ($label === '') {
            return null;
        }

        $key = collect(config("segmentation.{$list}"))
            ->search(fn (array $option, string $key) => strcasecmp($option[0], $label) === 0 || strcasecmp($key, $label) === 0);

        if ($key === false) {
            $warnings[] = "{$where}: \"{$label}\" is not an app option, left blank.";

            return null;
        }

        return $key;
    }

    /**
     * @param  list<string>  $warnings
     */
    public function contactTime(string $label, array &$warnings, string $where): ?string
    {
        if ($label === '') {
            return null;
        }

        $key = array_search(strtoupper($label), array_map('strtoupper', config('segmentation.contact_times')), true);

        if ($key === false) {
            $warnings[] = "{$where} Time of contact: \"{$label}\" is not a time slot, left blank.";

            return null;
        }

        return (string) $key;
    }

    /**
     * "07/11/2026" or "7/22/2026" (month first).
     *
     * @param  list<string>  $warnings
     */
    public function date(string $value, array &$warnings, string $where): ?CarbonImmutable
    {
        if ($value === '') {
            return null;
        }

        if (! preg_match('#^\d{1,2}/\d{1,2}/\d{4}$#', $value)) {
            $warnings[] = "{$where}: \"{$value}\" is not a date, left blank.";

            return null;
        }

        return CarbonImmutable::createFromFormat('!m/d/Y', $value);
    }
}
