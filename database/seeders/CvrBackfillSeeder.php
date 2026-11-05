<?php

namespace Database\Seeders;

use App\Models\CvrDayEntry;
use App\Models\CvrOrderEntry;
use App\Models\PancakeEngagement;
use App\Models\PancakeOrder;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * CVR Tracker: the CRAs' Upsell, Type of Order, Point of Contact, Assigned / Pancake and list order, and each day's
 * Unconfirmed and sheet rows that match no Pancake order (shown as missing), from their Oct 1–16, 2026 sheets (data/cvr-october-2026.json; each row already matched to its Pancake
 * order number). An order made under another seller account (e.g. AJ DELA CRUZ, or another CRA's) is counted for the
 * sheet's CRA. Safe to run again: only what is still empty is filled, so picks made in the app are never replaced.
 * CRAs are matched by display name.
 *
 * php artisan db:seed --class=CvrBackfillSeeder --force
 */
class CvrBackfillSeeder extends Seeder
{
    public const DATA = __DIR__.'/data/cvr-october-2026.json';

    public function run(): void
    {
        $data = json_decode(file_get_contents(self::DATA), true);
        $rows = collect($data['orders']);
        $users = User::whereIn('display_name', $rows->pluck('cra')->merge(array_column($data['days'], 'cra'))->unique())->get()->keyBy('display_name');
        $orders = PancakeOrder::whereIn('pancake_order_id', $rows->pluck('order_id'))->get(['pancake_order_id', 'seller_name', 'conversion_type'])->keyBy('pancake_order_id');
        $entries = CvrOrderEntry::whereIn('pancake_order_id', $rows->pluck('order_id'))->get()->keyBy('pancake_order_id');

        $filled = 0;
        $skipped = [];
        foreach ($rows as $row) {
            $cra = $users[$row['cra']] ?? null;
            $order = $orders[$row['order_id']] ?? null;
            if (! $cra || ! $order) {
                $skipped[] = "{$row['cra']} #{$row['order_id']}";

                continue;
            }

            $entry = $entries[$row['order_id']] ?? new CvrOrderEntry(['pancake_order_id' => $row['order_id']]);
            $ownOrder = $cra->pancake_name && $order->seller_name === PancakeEngagement::staffKey($cra->pancake_name);
            $sheet = [
                'user_id' => $ownOrder ? null : $cra->id,
                'position' => $row['position'],
                'lead_source' => $row['lead_source'],
                'upsell' => $row['upsell'],
                // Stored only when it differs from the order's CRD tag.
                'order_type' => $row['order_type'] === $order->conversion_type ? null : $row['order_type'],
                'point_of_contact' => $row['point_of_contact'],
            ];
            foreach ($sheet as $field => $value) {
                if ($entry->{$field} === null) {
                    $entry->{$field} = $value;
                }
            }

            if ($entry->isDirty()) {
                $entry->save();
                $filled++;
            }
        }

        $days = 0;
        foreach ($data['days'] as $row) {
            $cra = $users[$row['cra']] ?? null;
            if (! $cra) {
                continue;
            }
            $entry = CvrDayEntry::where('user_id', $cra->id)->whereDate('date', $row['date'])->firstOrNew([], ['user_id' => $cra->id, 'date' => $row['date']]);
            $entry->unconfirmed ??= $row['unconfirmed'];
            $entry->sheet_missing ??= $row['sheet_missing'] ?: null;
            if ($entry->isDirty()) {
                $entry->save();
                $days++;
            }
        }

        $this->command?->info("CVR Tracker: {$filled} orders and {$days} days (Unconfirmed, sheet rows with no order) filled from the sheets.");
        if ($skipped) {
            $this->command?->warn('Not found (left out): '.implode(', ', $skipped));
        }
    }
}
