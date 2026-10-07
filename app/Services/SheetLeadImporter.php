<?php

namespace App\Services;

use App\Models\Lead;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Imports leads from the Google Sheet tracker (CSV export) for days before the
 * app was live. The sheet decides the CRA, the CRD/FSD type and the tracking
 * fields; the Shecom delivered orders supply the order ID and tracking number.
 */
class SheetLeadImporter
{
    /**
     * Sheet feedback wording that differs from the app's labels.
     */
    private const FEEDBACK_ALIASES = [
        'MAY STOCKS PA' => 'still_have_stocks',
        'STOP BY DR.' => 'stopped_by_dr',
        'CX BLOCKED OUR PAGE' => 'blocked',
        'HINDI NAKAUSAP NI CRA' => 'no_verbal_conv',
    ];

    private ProductCatalog $catalog;

    /** @var array<string, array<string, list<array<string, mixed>>>> type => phone => orders */
    private array $orders = [];

    public function __construct()
    {
        $this->catalog = new ProductCatalog;
    }

    /**
     * Rows of the sheet that name a customer, in sheet order.
     *
     * @return list<array<string, mixed>>
     */
    public function parse(string $path, int $year): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Cannot open {$path}.");
        }

        fgetcsv($handle, escape: '');
        $rows = [];
        $section = null;
        $line = 1;

        while (($cells = fgetcsv($handle, escape: '')) !== false) {
            $line++;
            $cells = array_map(fn ($cell) => trim((string) $cell), array_pad($cells, 19, ''));

            if ($cells[0] !== '') {
                $section = $cells[0];
            }

            if ($cells[1] === '') {
                continue;
            }

            $rows[] = [
                'line' => $line,
                'lead_type' => Str::startsWith(strtoupper((string) $section), 'CRD') ? Lead::TYPE_CRD : Lead::TYPE_FSD,
                'customer_name' => $cells[1],
                'qty' => max(1, (int) $cells[2]),
                'product' => $cells[3],
                'phone_number' => $cells[4],
                'delivered_date' => $this->monthDay($cells[5], $year),
                'est_out_of_stock_date' => $this->monthDay($cells[7], $year),
                'cra' => $cells[9],
                'status' => $cells[10],
                'repeat_purchase' => $cells[11],
                'customer_tag' => $cells[12],
                'contact_date' => $cells[13],
                'contact_time' => $cells[14],
                'feedback' => $cells[15],
                'notes' => $cells[16],
                'callback_date' => $cells[17],
                'call_recording_url' => $cells[18],
                'year' => $year,
            ];
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param  array{crd: list<array<string, mixed>>, fsd: list<array<string, mixed>>}  $orders
     */
    public function useOrders(array $orders): void
    {
        $this->orders = [];

        foreach ($orders as $type => $list) {
            foreach ($list as $order) {
                $this->orders[$type][LeadGenerator::normalizePhone((string) ($order['phone_number'] ?? ''))][] = $order;
            }
        }
    }

    /**
     * Create or update the leads for $rows, assigned to the CRA the sheet names.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, int>  $craIds  sheet CRA name (lower case) => user id
     * @return array{saved: int, created: int, matched: int, unmatched: list<string>, warnings: list<string>}
     */
    public function import(array $rows, array $craIds, bool $dryRun = false): array
    {
        $report = ['saved' => 0, 'created' => 0, 'matched' => 0, 'unmatched' => [], 'warnings' => []];
        $usedOrderIds = [];

        // Look the batch's leads up in one query: a remote database costs a round trip per query.
        $lookedUp = collect($rows)
            ->flatMap(fn (array $row) => [(string) ($this->findOrder($row, [])['order_id'] ?? ''), $this->sheetOrderId($row)])
            ->filter()->unique()->values();
        $known = $lookedUp->chunk(500)
            ->flatMap(fn (Collection $ids) => Lead::whereIn('order_id', $ids->all())->get())
            ->keyBy('order_id');
        $lookedUp = $lookedUp->flip();
        $find = fn (string $orderId) => $known[$orderId] ?? (isset($lookedUp[$orderId]) ? null : Lead::firstWhere('order_id', $orderId));

        foreach ($rows as $row) {
            $where = "line {$row['line']} ({$row['customer_name']})";
            $craId = $craIds[strtolower($row['cra'])] ?? null;

            if ($craId === null) {
                $report['warnings'][] = "{$where}: no app user for CRA \"{$row['cra']}\", skipped.";

                continue;
            }

            $order = $this->findOrder($row, $usedOrderIds);
            $orderId = $order ? (string) $order['order_id'] : $this->sheetOrderId($row);

            $existing = $find($orderId);

            if ($existing && ! $existing->est_out_of_stock_date->isSameDay($row['est_out_of_stock_date'])) {
                $report['warnings'][] = "{$where}: order {$orderId} is already a lead for {$existing->est_out_of_stock_date->toDateString()}; used a sheet ID instead.";
                $order = null;
                $orderId = $this->sheetOrderId($row);
                $existing = $find($orderId);
            }

            $usedOrderIds[$orderId] = true;

            if ($order) {
                $report['matched']++;
            } else {
                $report['unmatched'][] = "{$where}, {$row['est_out_of_stock_date']->format('M j')}, {$row['cra']}";
            }

            $lead = $existing ?? new Lead(['order_id' => $orderId]);
            $report['created'] += $lead->exists ? 0 : 1;
            $lead->fill($this->attributes($row, $order, $craId, $report['warnings'], $where));

            if (! $dryRun) {
                $lead->save();
            }

            $report['saved']++;
        }

        return $report;
    }

    /**
     * The delivered order this row is about: same phone and delivered date,
     * from the sheet's list (CRD or FSD) first, then the other one.
     *
     * @param  array<string, true>  $used
     * @return array<string, mixed>|null
     */
    private function findOrder(array $row, array $used): ?array
    {
        $phone = LeadGenerator::normalizePhone($row['phone_number']);
        $types = $row['lead_type'] === Lead::TYPE_CRD ? ['crd', 'fsd'] : ['fsd', 'crd'];
        $product = $this->catalog->match($row['product'])?->name ?? $row['product'];

        foreach ($types as $type) {
            // No phone in the sheet: look the customer up by name instead.
            $orders = $phone !== ''
                ? $this->orders[$type][$phone] ?? []
                : collect($this->orders[$type] ?? [])->flatten(1)
                    ->filter(fn (array $o) => strcasecmp(trim((string) ($o['customer_name'] ?? '')), $row['customer_name']) === 0)
                    ->all();

            $candidates = collect($orders)
                ->filter(fn (array $o) => substr((string) ($o['delivered_date'] ?? ''), 0, 10) === $row['delivered_date']->toDateString())
                ->reject(fn (array $o) => isset($used[(string) $o['order_id']]))
                ->unique('order_id');

            if ($candidates->isEmpty()) {
                continue;
            }

            return $candidates
                ->sortByDesc(fn (array $o) => ($this->productOf($o) === $product ? 2 : 0) + ((int) ($o['qty'] ?? 0) === $row['qty'] ? 1 : 0))
                ->first();
        }

        return null;
    }

    private function productOf(array $order): string
    {
        $raw = (string) ($order['product_name'] ?? $order['product'] ?? '');

        return $this->catalog->match($raw)?->name ?? $raw;
    }

    /**
     * A stable ID for a row with no Shecom order, so re-running updates it.
     */
    private function sheetOrderId(array $row): string
    {
        $who = LeadGenerator::normalizePhone($row['phone_number']) ?: Str::slug($row['customer_name']);

        return 'SHEET-'.$who.'-'.$row['delivered_date']->format('Ymd').'-'.Str::slug($row['product']);
    }

    /**
     * @param  array<string, mixed>|null  $order
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    private function attributes(array $row, ?array $order, int $craId, array &$warnings, string $where): array
    {
        $raw = $order ? (string) ($order['product_name'] ?? $order['product'] ?? $row['product']) : $row['product'];
        $product = $this->catalog->match($row['product']) ?? $this->catalog->match($raw);
        $est = $row['est_out_of_stock_date'];
        $days = (int) ($order['consumption_days_per_unit'] ?? 0)
            ?: (int) $product?->consumption_days
            ?: max(1, (int) round(($row['delivered_date']->diffInDays($est) + 1) / $row['qty']));

        $contactDate = $this->date($row['contact_date'], $row['year'], $warnings, "{$where} Date of Contact");
        $status = $this->optionKey('statuses', $row['status'], $warnings, "{$where} Status");
        $notes = $row['notes'] !== '' ? $row['notes'] : null;
        // Updates are dated to the contact day (else the lead day) so today's views treat them as old work.
        $updatedAt = CarbonImmutable::parse(($contactDate ?? $est)->toDateString().' 12:00', config('segmentation.timezone'))->utc();

        return [
            'tracking_number' => ($order['tracking_number'] ?? null) ?: null,
            'customer_name' => $row['customer_name'],
            'phone_number' => $row['phone_number'] ?: (string) ($order['phone_number'] ?? ''),
            'product_name' => $product?->name ?? $row['product'],
            'product_raw' => $raw,
            'qty' => $row['qty'],
            'delivered_date' => $row['delivered_date']->toDateString(),
            'consumption_days' => $days,
            'est_out_of_stock_date' => $est->toDateString(),
            'lead_type' => $row['lead_type'],
            'assigned_to' => $craId,
            'assigned_at' => CarbonImmutable::parse($est->toDateString(), config('segmentation.timezone'))->utc(),
            'status' => $status,
            'status_updated_by' => $status ? $craId : null,
            'status_updated_at' => $status ? $updatedAt : null,
            'repeat_purchase' => $this->optionKey('repeat_purchase', $row['repeat_purchase'], $warnings, "{$where} Repeat Purchase"),
            'customer_tag' => $this->optionKey('customer_tags', $row['customer_tag'], $warnings, "{$where} Customer Tagging"),
            'contact_date' => $contactDate?->toDateString(),
            'contact_time' => $this->contactTime($row['contact_time'], $warnings, $where),
            'feedback' => $this->feedback($row['feedback'], $warnings, $where),
            'callback_date' => $this->date($row['callback_date'], $row['year'], $warnings, "{$where} Callback date")?->toDateString(),
            'call_recording_url' => $row['call_recording_url'] !== '' ? $row['call_recording_url'] : null,
            'notes' => $notes,
            'notes_updated_by' => $notes ? $craId : null,
            'notes_updated_at' => $notes ? $updatedAt : null,
        ];
    }

    /**
     * Key of a [key => [label, classes]] config list whose label matches $label.
     *
     * @param  list<string>  $warnings
     */
    private function optionKey(string $list, string $label, array &$warnings, string $where): ?string
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
    private function feedback(string $label, array &$warnings, string $where): ?string
    {
        return self::FEEDBACK_ALIASES[strtoupper($label)]
            ?? $this->optionKey('feedback', $label, $warnings, "{$where} Customer's Feedback");
    }

    /**
     * @param  list<string>  $warnings
     */
    private function contactTime(string $label, array &$warnings, string $where): ?string
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
     * "09/20/2026" or "Sept 28". Any year before $year is a typo for $year.
     *
     * @param  list<string>  $warnings
     */
    private function date(string $value, int $year, array &$warnings, string $where): ?CarbonImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            $date = preg_match('#^\d{1,2}/\d{1,2}/\d{4}$#', $value)
                ? CarbonImmutable::createFromFormat('!m/d/Y', $value)
                : $this->monthDay($value, $year);
        } catch (\Throwable) {
            $warnings[] = "{$where}: \"{$value}\" is not a date, left blank.";

            return null;
        }

        return $date->year < $year ? $date->setYear($year) : $date;
    }

    /**
     * "June 19" / "Sept 28" in $year.
     */
    private function monthDay(string $value, int $year): CarbonImmutable
    {
        $value = preg_replace('/^Sept\b/i', 'Sep', $value);

        return CarbonImmutable::parse("{$value} {$year}")->startOfDay();
    }

    /**
     * Sheet rows for one lead day and/or CRA.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    public static function batch(array $rows, ?string $date = null, ?string $cra = null): Collection
    {
        return collect($rows)
            ->when($date, fn (Collection $c) => $c->filter(fn (array $r) => $r['est_out_of_stock_date']->toDateString() === $date))
            ->when($cra, fn (Collection $c) => $c->filter(fn (array $r) => strcasecmp($r['cra'], $cra) === 0))
            ->values();
    }
}
