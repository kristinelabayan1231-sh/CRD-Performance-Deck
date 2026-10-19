<?php

namespace App\Services;

use App\Models\DeliveredOrder;
use App\Models\Lead;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Imports the old tracker's weekly "Assigned Leads" sheets (August 2026). These sheets have no reliable Order ID,
 * so each row's order is found in the delivered orders by contact number, delivered date and product (the
 * customer name can differ from Pancake). Rows keep their CRA and are listed on the sheet's Est. Out of Stock.
 * An order that is already an unassigned (pool) lead gets the sheet's CRA and work, keeping its out-of-stock date;
 * an order already assigned elsewhere is skipped and reported. When an order is in more than one file, the later
 * file wins.
 */
class AssignedLeadsImporter
{
    /** Sheet wording that differs from the app's option labels. */
    private const ALIASES = [
        'feedback' => ['HINDI NAKAUSAP NI CRA' => 'no_verbal_conv', 'MAY STOCKS PA' => 'still_have_stocks', 'STOP BY DR.' => 'stopped_by_dr', 'CX BLOCKED OUR PAGE' => 'blocked'],
        'repeat_purchase' => ['RESERVATION' => 'reserve'],
        'contact_times' => ['11:00AM-12:00PM' => '11'],
    ];

    /** The sheet's default status: a row with only this and nothing else filled was never worked. */
    private const DEFAULT_STATUS = 'active';

    private ProductCatalog $catalog;

    /** @var array<string, true> orders already imported by this run, which a later file updates */
    private array $seenOrders = [];

    public function __construct(private TrackerExportImporter $sheet)
    {
        $this->catalog = new ProductCatalog;
    }

    /**
     * Rows of one sheet. Columns are found by header name (the weeks differ). A first-column label such as
     * "Facebook Sales Department LEADS" or "CRD LEADS" sets the type of the rows under it.
     *
     * @return list<array<string, mixed>>
     */
    public function parse(string $path): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Cannot open {$path}.");
        }

        $header = array_map(fn ($cell) => trim((string) $cell), fgetcsv($handle, escape: ''));
        $column = array_flip($header);
        $type = self::sectionType($header[0]);
        $rows = [];
        $line = 1;

        while (($cells = fgetcsv($handle, escape: '')) !== false) {
            $line++;
            $cells = array_map(fn ($cell) => trim((string) $cell), array_pad($cells, count($header), ''));
            $cell = fn (string $name) => isset($column[$name]) ? $cells[$column[$name]] : '';

            if ($cells[0] !== '' && ! ctype_digit($cells[0])) {
                $type = self::sectionType($cells[0]) ?? $type;
            }

            if ($cell('Contact #') === '') {
                continue;
            }

            $year = $cell('Year') ?: '2026';
            $product = $cell('Product');
            // "3 Pterygium Drops": the leading number is the quantity when the Qty column is blank.
            preg_match('/^(\d+)\s+(.*)$/', $product, $m);
            $callback = $cell('Callback date');
            $recording = $cell('Call Recording Link');

            if (Str::startsWith($callback, 'http') && $recording === '') {
                [$callback, $recording] = ['', $callback];
            }

            $rows[] = [
                'line' => $line,
                'sheet_order_id' => ctype_digit($cells[0]) ? $cells[0] : null,
                'lead_type' => $type,
                'customer_name' => $cell('Customer Name'),
                'phone_number' => TrackerExportImporter::phone($cell('Contact #')),
                'product' => $m[2] ?? $product,
                'qty' => max(1, (int) ($cell('Qty') ?: ($m[1] ?? 1))),
                'delivered_date' => self::sheetDate($cell('Delivered Date'), $year),
                'est' => self::sheetDate($cell('Est. Out of Stock'), $year),
                'cra' => $cell('Assigned to'),
                'status' => $cell('Status'),
                'repeat_purchase' => $cell('Repeat Purchase?'),
                'customer_tag' => $cell('Customer Tagging'),
                'contact_date' => $cell('Date of Contact'),
                'contact_time' => $cell('Time of contact'),
                'feedback' => $cell("Customer's Feedback"),
                'notes' => $cell('Note from associate'),
                'callback_date' => $callback,
                'call_recording_url' => $recording,
            ];
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, int>  $craIds  sheet CRA name (lower case) => user id
     * @return array{created: int, updated: int, skipped: list<string>, unmatched: list<string>, warnings: list<string>, leads: list<array<string, mixed>>}
     */
    public function import(array $rows, array $craIds, bool $dryRun = false): array
    {
        $report = ['created' => 0, 'updated' => 0, 'skipped' => [], 'unmatched' => [], 'warnings' => [], 'leads' => []];

        foreach ($rows as $row) {
            $where = "line {$row['line']} ({$row['customer_name']}, {$row['phone_number']})";
            $craId = $craIds[strtolower($row['cra'])] ?? null;

            if ($craId === null) {
                $report['skipped'][] = "{$where}: no CRA mapped for \"{$row['cra']}\".";

                continue;
            }

            if ($row['est'] === null || $row['delivered_date'] === null) {
                $report['unmatched'][] = "{$where}: no delivered date or Est. Out of Stock.";

                continue;
            }

            $orderId = $this->orderId($row);

            if ($orderId === null) {
                $report['unmatched'][] = "{$where}: no delivered {$row['product']} order on {$row['delivered_date']->toDateString()} for this number.";

                continue;
            }

            $tracking = $this->tracking($row, $craId, $report['warnings'], $where);
            $lead = Lead::firstWhere('order_id', $orderId);

            $again = isset($this->seenOrders[$orderId]);

            if ($lead && $lead->assigned_to !== null && ! $again) {
                $report['skipped'][] = "{$where}: order {$orderId} is already {$lead->assignee?->displayName()}'s lead for {$lead->listed_on->toDateString()}.";

                continue;
            }

            $type = $row['lead_type'] ?? $lead?->lead_type ?? $this->typeByPhone($row['phone_number']);

            if ($lead || $again) {
                // Keeps the order's out-of-stock date; the sheet's date is the day it's listed on.
                $attributes = $tracking + ['lead_type' => $type];
                $report['updated']++;
            } else {
                $attributes = $this->newLead($row, $orderId, $type) + $tracking;
                $report['created']++;
            }

            $report['leads'][] = ['order_id' => $orderId, 'existing' => $lead || $again] + $attributes;
            $this->seenOrders[$orderId] = true;

            if (! $dryRun) {
                $lead ? $lead->update($attributes) : Lead::create($attributes);
            }
        }

        return $report;
    }

    /**
     * The delivered order with this contact number, delivered date and product.
     */
    private function orderId(array $row): ?string
    {
        $key = substr(preg_replace('/\D+/', '', $row['phone_number']), -10);
        $product = $this->productKey($row['product']);

        // Matched on the last 10 digits: Pancake numbers come in any format (09…, +639…, even +09…).
        $ids = DeliveredOrder::whereDate('delivered_date', $row['delivered_date'])
            ->where('phone_number', 'like', '%'.$key)
            ->get(['order_id', 'product_raw'])
            ->filter(fn (DeliveredOrder $order) => $this->productKey($order->product_raw) === $product)
            ->pluck('order_id')->unique();

        if ($row['sheet_order_id'] !== null && $ids->contains($row['sheet_order_id'])) {
            return $row['sheet_order_id'];
        }

        return $ids->count() === 1 ? (string) $ids->first() : null;
    }

    private function productKey(string $raw): string
    {
        return strtolower($this->catalog->match($raw)?->name ?? Str::before(trim($raw).' ', ' '));
    }

    /**
     * No section label: the type of the customer's other leads (the June/July rows), else FSD.
     */
    private function typeByPhone(string $phone): string
    {
        $key = substr(preg_replace('/\D+/', '', $phone), -10);

        return Lead::where('phone_number', 'like', '%'.$key)->withoutPosSales()->value('lead_type') ?? Lead::TYPE_FSD;
    }

    /**
     * @return array<string, mixed>
     */
    private function newLead(array $row, string $orderId, string $type): array
    {
        $product = $this->catalog->match($row['product']);

        return [
            'order_id' => $orderId,
            'customer_name' => $row['customer_name'],
            'phone_number' => $row['phone_number'],
            'product_name' => $product?->name ?? $row['product'],
            'product_raw' => $row['product'],
            'qty' => $row['qty'],
            'delivered_date' => $row['delivered_date']->toDateString(),
            'consumption_days' => (int) $product?->consumption_days ?: TrackerExportImporter::DEFAULT_CONSUMPTION_DAYS,
            'est_out_of_stock_date' => $row['est']->toDateString(),
            'lead_type' => $type,
        ];
    }

    /**
     * The CRA, the day it's listed on and the sheet's tracking columns. Only the sheet's default status with
     * nothing else filled counts as never worked.
     *
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    private function tracking(array $row, int $craId, array &$warnings, string $where): array
    {
        $contactDate = $this->sheet->date($row['contact_date'], $warnings, "{$where} Date of Contact");

        if ($contactDate?->year === 2025) {
            $warnings[] = "{$where}: Date of Contact {$contactDate->format('m/d/Y')} read as 2026.";
            $contactDate = $contactDate->setYear(2026);
        }

        $status = $this->option('statuses', $row['status'], $warnings, "{$where} Status");
        $feedback = $this->option('feedback', $row['feedback'], $warnings, "{$where} Customer's Feedback");
        $repeat = $this->option('repeat_purchase', $row['repeat_purchase'], $warnings, "{$where} Repeat Purchase");
        $notes = $row['notes'] !== '' ? $row['notes'] : null;
        $worked = $contactDate || $notes || $feedback || ($status && $status !== self::DEFAULT_STATUS) || in_array($repeat, ['yes', 'reserve'], true);
        $updatedAt = CarbonImmutable::parse(($contactDate ?? $row['est'])->toDateString().' 12:00', config('segmentation.timezone'))->utc();

        return [
            'listed_on' => $row['est']->toDateString(),
            'assigned_to' => $craId,
            'assigned_at' => CarbonImmutable::parse($row['est']->toDateString(), config('segmentation.timezone'))->utc(),
            'status' => $worked ? $status : null,
            'status_updated_by' => $worked && $status ? $craId : null,
            'status_updated_at' => $worked && $status ? $updatedAt : null,
            'repeat_purchase' => $worked ? $repeat : null,
            // Bought on the day it was contacted (the sheet has no purchase date), not on the import day.
            'repeat_purchase_on' => $worked && $repeat === 'yes' ? ($contactDate ?? $row['est'])->toDateString() : null,
            'customer_tag' => $worked ? $this->option('customer_tags', $row['customer_tag'], $warnings, "{$where} Customer Tagging") : null,
            'contact_date' => $contactDate?->toDateString(),
            'contact_time' => $worked ? $this->contactTime($row['contact_time'], $warnings, $where) : null,
            'feedback' => $worked ? $feedback : null,
            'callback_date' => $this->sheet->date($row['callback_date'], $warnings, "{$where} Callback date")?->toDateString(),
            'call_recording_url' => $row['call_recording_url'] ?: null,
            'notes' => $notes,
            'notes_updated_by' => $notes ? $craId : null,
            'notes_updated_at' => $notes ? $updatedAt : null,
        ];
    }

    /**
     * @param  list<string>  $warnings
     */
    private function option(string $list, string $label, array &$warnings, string $where): ?string
    {
        return self::ALIASES[$list][strtoupper($label)] ?? $this->sheet->optionKey($list, $label, $warnings, $where);
    }

    /**
     * @param  list<string>  $warnings
     */
    private function contactTime(string $label, array &$warnings, string $where): ?string
    {
        return self::ALIASES['contact_times'][strtoupper($label)] ?? $this->sheet->contactTime($label, $warnings, $where);
    }

    private static function sectionType(string $label): ?string
    {
        return match (true) {
            Str::contains($label, 'CRD LEADS', ignoreCase: true) => Lead::TYPE_CRD,
            Str::contains($label, 'Sales Department', ignoreCase: true) => Lead::TYPE_FSD,
            default => null,
        };
    }

    /**
     * "August 17" with the Year column.
     */
    private static function sheetDate(string $value, string $year): ?CarbonImmutable
    {
        $date = $value === '' ? false : CarbonImmutable::createFromFormat('!F j Y', "{$value} {$year}");

        return $date ?: null;
    }
}
