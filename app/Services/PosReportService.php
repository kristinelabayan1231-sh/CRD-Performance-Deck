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

        return array_values(array_filter($rows, function (array $row) use ($filterTerm) {
            $sellerName = trim($row['Assigning seller'] ?? '');

            return $sellerName !== '' && str_contains(strtolower($sellerName), $filterTerm);
        }));
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
    public function filterRows(array $rows, Carbon $start, Carbon $end, ?string $sellerName = null): array
    {
        $startOfRange = $start->copy()->startOfDay();
        $endOfRange = $end->copy()->endOfDay();

        return array_values(array_filter($rows, function (array $row) use ($startOfRange, $endOfRange, $sellerName) {
            if ($sellerName !== null && trim($row['Assigning seller'] ?? '') !== $sellerName) {
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
