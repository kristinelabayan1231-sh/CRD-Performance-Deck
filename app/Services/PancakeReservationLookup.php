<?php

namespace App\Services;

use App\Models\CustomerLink;
use App\Models\Lead;
use App\Models\LogisticsOrder;
use App\Models\PancakeOrder;
use Carbon\CarbonImmutable;

/**
 * Fills a Reserved - Pancake entry (Qty to Type) from the customer's last order, found by contact number (merged
 * numbers too, Customer Database):
 *
 * - their latest lead (not one added from a CRA's POS sale, whose dates are the order's) or latest delivered order
 *   (logistics retention report), whichever was delivered later. A lead is
 *   used as it is; a delivered order is worked out like a lead: product and days per unit from Settings → Product
 *   Consumption, out of stock = delivered + qty × days − 1, CRD Lead when CRD delivered it, else FSD Lead;
 * - else their latest Pancake POS order not delivered yet: product and qty only, CRD Lead when they had a
 *   CRA-handled order before, else FSD Lead.
 *
 * Only products on the Product Consumption list, as for leads. Null when the number has none of these.
 */
class PancakeReservationLookup
{
    public const FROM_LEAD = 'lead';

    public const FROM_DELIVERED = 'delivered';

    public const FROM_POS = 'pancake';

    public function __construct(private CustomerDatabase $customers) {}

    /**
     * @return array{customer_name: string, phone_number: string, phone_key: string, product_name: ?string, qty: ?int, delivered_date: ?string, consumption_days: ?int, est_out_of_stock_date: ?string, lead_type: ?string, filled_from: string}|null
     */
    public function find(string $phone): ?array
    {
        $phoneKey = LeadGenerator::normalizePhone($phone);

        if (strlen($phoneKey) < 10) {
            return null;
        }

        $numbers = $this->numbers($phoneKey);
        $catalog = new ProductCatalog;

        // Lead numbers are stored as entered: 9…, 09…, 639… or +639….
        $lead = Lead::whereIn('phone_number', collect($numbers)->flatMap(fn (string $key) => [$key, '0'.$key, '63'.$key, '+63'.$key])->all())
            ->withoutPosSales()->orderByDesc('delivered_date')->orderByDesc('listed_on')->first();
        $delivery = LogisticsOrder::whereIn('phone_key', $numbers)->where('non_crd', false)
            ->orderByDesc('delivered_date')->orderByDesc('id')->first();

        if ($lead && (! $delivery || $lead->delivered_date->greaterThanOrEqualTo($delivery->delivered_date))) {
            return [
                ...$this->contact($lead->customer_name, $lead->phone_number, $phoneKey),
                'product_name' => $lead->product_name,
                'qty' => $lead->qty,
                'delivered_date' => $lead->delivered_date->toDateString(),
                'consumption_days' => $lead->consumption_days ?: null,
                'est_out_of_stock_date' => $lead->est_out_of_stock_date->toDateString(),
                'lead_type' => $lead->lead_type,
                'filled_from' => self::FROM_LEAD,
            ];
        }

        if ($delivery) {
            $deliveredOn = $delivery->delivered_date->toDateString();
            $product = $catalog->match((string) $delivery->product, $deliveredOn);
            $qty = max(1, (int) $delivery->qty);
            $days = $product?->consumption_days ?: null;

            return [
                ...$this->contact($delivery->customer_name, $delivery->phone_number, $phoneKey),
                'product_name' => $product?->name ?? $delivery->product,
                'qty' => $qty,
                'delivered_date' => $deliveredOn,
                'consumption_days' => $days,
                'est_out_of_stock_date' => $days ? $delivery->delivered_date->addDays($qty * $days - 1)->toDateString() : null,
                'lead_type' => $delivery->team === LogisticsOrder::TEAM_CRD ? Lead::TYPE_CRD : Lead::TYPE_FSD,
                'filled_from' => self::FROM_DELIVERED,
            ];
        }

        $order = PancakeOrder::whereIn('phone_key', $numbers)->crdProducts()->counted()
            ->orderByDesc('ordered_at')->orderByDesc('id')->first();

        if (! $order) {
            return null;
        }

        $items = collect($order->items ?? []);
        $names = $items->pluck('name')->filter()->values();
        $product = $names->map(fn (string $name) => $catalog->match($name, $order->ordered_on->toDateString()))->filter()->first();

        return [
            ...$this->contact($order->customer_name, $order->phone_number, $phoneKey),
            'product_name' => $product?->name ?? $names->first(),
            'qty' => $items->isEmpty() ? null : max(1, (int) $items->sum('qty')),
            'delivered_date' => null,
            'consumption_days' => $product?->consumption_days ?: null,
            'est_out_of_stock_date' => null,
            'lead_type' => $this->customers->hasCraOrderBefore($phoneKey, CarbonImmutable::now()) ? Lead::TYPE_CRD : Lead::TYPE_FSD,
            'filled_from' => self::FROM_POS,
        ];
    }

    /**
     * The customer's numbers (last 10 digits): the main one they are merged under and every number filed under it.
     *
     * @return list<string>
     */
    private function numbers(string $phoneKey): array
    {
        $primary = CustomerLink::where('phone_key', $phoneKey)->value('primary_phone_key') ?? $phoneKey;

        return array_values(array_unique([$phoneKey, $primary, ...CustomerLink::where('primary_phone_key', $primary)->pluck('phone_key')->all()]));
    }

    /**
     * @return array{customer_name: string, phone_number: string, phone_key: string}
     */
    private function contact(?string $name, ?string $phone, string $phoneKey): array
    {
        return ['customer_name' => trim((string) $name), 'phone_number' => (string) ($phone ?: '0'.$phoneKey), 'phone_key' => $phoneKey];
    }
}
