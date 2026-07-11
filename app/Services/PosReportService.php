<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

class PosReportService
{
    /**
     * Fetch the published POS CSV and return rows for sellers matching the
     * configured name filter (e.g. "CRD"), regardless of date.
     *
     * @return array<int, array<string, string>>
     */
    public function loadSellerRows(): array
    {
        $response = Http::timeout(30)->get(config('pos_report.csv_url'));
        $response->throw();

        $rows = $this->parseCsv($response->body());
        $filterTerm = strtolower(config('pos_report.seller_filter'));
        $aliases = config('pos_report.seller_aliases', []);

        $filtered = array_filter($rows, function (array $row) use ($filterTerm) {
            $sellerName = trim($row['Assigning seller'] ?? '');

            return $sellerName !== '' && str_contains(strtolower($sellerName), $filterTerm);
        });

        return array_values(array_map(function (array $row) use ($aliases) {
            // The CSV has inconsistent internal whitespace (e.g. double
            // spaces after "CRD"), which would otherwise defeat both alias
            // matching and exact-name grouping elsewhere.
            $sellerName = preg_replace('/\s+/', ' ', trim($row['Assigning seller'] ?? ''));
            $row['Assigning seller'] = $aliases[$sellerName] ?? $sellerName;

            return $row;
        }, $filtered));
    }

    /**
     * Distinct, sorted seller names from the given rows.
     *
     * @param  array<int, array<string, string>>  $rows
     * @return array<int, string>
     */
    public function sellerNames(array $rows): array
    {
        $names = array_unique(array_map(fn ($row) => trim($row['Assigning seller']), $rows));
        sort($names);

        return $names;
    }

    /**
     * Rows within the date range, optionally restricted to a single seller.
     *
     * @param  array<int, array<string, string>>  $rows
     * @return array<int, array<string, string>>
     */
    public function filterRows(array $rows, Carbon $start, Carbon $end, ?array $sellerNames = null): array
    {
        $startOfRange = $start->copy()->startOfDay();
        $endOfRange = $end->copy()->endOfDay();

        return array_values(array_filter($rows, function (array $row) use ($startOfRange, $endOfRange, $sellerNames) {
            if ($sellerNames !== null && $sellerNames !== [] && ! in_array(trim($row['Assigning seller'] ?? ''), $sellerNames, true)) {
                return false;
            }

            $salesDate = $this->parseDate($row['Sales Date'] ?? null);

            return $salesDate && $salesDate->gte($startOfRange) && $salesDate->lte($endOfRange);
        }));
    }

    /**
     * Aggregate already-filtered rows into a per-seller, per-product report
     * (Product, Sales Value, Parcel Qty., Product Cost).
     *
     * Each CSV row is one order item ("parcel"), so Parcel Qty. is a row
     * count rather than a summed quantity field.
     *
     * @param  array<int, array<string, string>>  $filteredRows
     */
    public function buildSellerReport(array $filteredRows): array
    {
        $report = [];

        foreach ($filteredRows as $row) {
            $rowSeller = trim($row['Assigning seller'] ?? '');
            $product = trim($row['Product Variation'] ?? '') ?: 'Unknown product';
            $salesValue = $this->parseNumber($row['Unit price'] ?? '0');
            $cost = $this->parseNumber($row['P.COST'] ?? '0');

            $report[$rowSeller] ??= [];

            $report[$rowSeller][$product] ??= [
                'product' => $product,
                'sales_value' => 0.0,
                'parcel_qty' => 0,
                'product_cost' => 0.0,
            ];

            $report[$rowSeller][$product]['sales_value'] += $salesValue;
            $report[$rowSeller][$product]['parcel_qty'] += 1;
            $report[$rowSeller][$product]['product_cost'] += $cost;
        }

        ksort($report);

        foreach ($report as $name => $products) {
            usort($products, fn ($a, $b) => $b['sales_value'] <=> $a['sales_value']);
            $report[$name] = $products;
        }

        return $report;
    }

    /**
     * Aggregate already-filtered rows by an arbitrary CSV column (e.g.
     * "PRODUCT NAME", "By region", "Status") into Sales Value, Parcel Qty.,
     * and Product Cost totals, sorted by Sales Value descending.
     *
     * @param  array<int, array<string, string>>  $filteredRows
     * @return array<int, array{label: string, sales_value: float, parcel_qty: int, product_cost: float}>
     */
    public function aggregateBy(array $filteredRows, string $column): array
    {
        $groups = [];

        foreach ($filteredRows as $row) {
            $label = trim($row[$column] ?? '') ?: 'Unknown';

            $groups[$label] ??= [
                'label' => $label,
                'sales_value' => 0.0,
                'parcel_qty' => 0,
                'product_cost' => 0.0,
            ];

            $groups[$label]['sales_value'] += $this->parseNumber($row['Unit price'] ?? '0');
            $groups[$label]['parcel_qty'] += 1;
            $groups[$label]['product_cost'] += $this->parseNumber($row['P.COST'] ?? '0');
        }

        $groups = array_values($groups);
        usort($groups, fn ($a, $b) => $b['sales_value'] <=> $a['sales_value']);

        return $groups;
    }

    /**
     * Aggregate already-filtered rows into per-category trend series across
     * a small set of days — one point per day per category, for a line
     * chart comparing e.g. products, regions, statuses, or sellers day by
     * day. Categories are sorted by their total across all days descending.
     *
     * @param  array<int, array<string, string>>  $filteredRows
     * @param  array<int, \Carbon\Carbon>  $days
     * @return array<int, array{label: string, total: float, points: array<int, array{date: string, sales_value: float, parcel_qty: int, product_cost: float}>}>
     */
    public function aggregateByDay(array $filteredRows, array $days, string $column): array
    {
        $dayKeys = array_map(fn (Carbon $day) => $day->toDateString(), $days);

        $grouped = [];

        foreach ($filteredRows as $row) {
            $salesDate = $this->parseDate($row['Sales Date'] ?? null);

            if (! $salesDate) {
                continue;
            }

            $dayKey = $salesDate->toDateString();

            if (! in_array($dayKey, $dayKeys, true)) {
                continue;
            }

            $label = trim($row[$column] ?? '') ?: 'Unknown';

            $grouped[$label][$dayKey] ??= ['sales_value' => 0.0, 'parcel_qty' => 0, 'product_cost' => 0.0];
            $grouped[$label][$dayKey]['sales_value'] += $this->parseNumber($row['Unit price'] ?? '0');
            $grouped[$label][$dayKey]['parcel_qty'] += 1;
            $grouped[$label][$dayKey]['product_cost'] += $this->parseNumber($row['P.COST'] ?? '0');
        }

        $series = [];

        foreach ($grouped as $label => $byDay) {
            $points = [];
            $total = 0.0;

            foreach ($dayKeys as $dayKey) {
                $point = $byDay[$dayKey] ?? ['sales_value' => 0.0, 'parcel_qty' => 0, 'product_cost' => 0.0];
                $points[] = array_merge(['date' => $dayKey], $point);
                $total += $point['sales_value'];
            }

            $series[] = ['label' => $label, 'total' => $total, 'points' => $points];
        }

        usort($series, fn ($a, $b) => $b['total'] <=> $a['total']);

        return $series;
    }

    /**
     * @return array<int, array<string, string>>
     */
    protected function parseCsv(string $csv): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);

        $header = fgetcsv($stream);
        $rows = [];

        while (($data = fgetcsv($stream)) !== false) {
            if (count($data) !== count($header)) {
                continue;
            }

            $rows[] = array_combine($header, $data);
        }

        fclose($stream);

        return $rows;
    }

    protected function parseDate(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function parseNumber(string $value): float
    {
        return (float) preg_replace('/[^0-9.\-]/', '', $value) ?: 0.0;
    }
}
