<?php

namespace App\Services;

use App\Models\ExclusiveFsaAccount;
use App\Models\PancakeOrder;

/**
 * An exclusive CRA's FSA customers: customers no CRD account has ever sold to (current or former team accounts), whose
 * latest order was sold by one of the FSA accounts set for that CRA (Settings → Exclusive Lead Tracker, ExclusiveFsaAccount).
 * Their leads are hers alone: LeadGenerator::ownerFor() makes her their CRA when no CRA owns them, so the daily
 * assignment and the backlog fill keep them for her list, as they do her other customers. Canceled and deleted orders
 * don't count.
 */
class ExclusiveFsaCustomers
{
    private const CHUNK = 1000;

    /**
     * The exclusive CRA each of these numbers' customers belongs to by their FSA (numbers with none left out).
     *
     * @param  list<string>  $phoneKeys
     * @return array<string, int> phone key => CRA id
     */
    public function for(array $phoneKeys): array
    {
        $craOf = $this->accounts();
        $phoneKeys = array_values(array_unique(array_filter(array_map('strval', $phoneKeys))));

        if ($craOf === [] || $phoneKeys === []) {
            return [];
        }

        $crdAccounts = PancakeOrder::crdAccounts();
        $latest = [];
        $soldByCrd = [];

        foreach (array_chunk($phoneKeys, self::CHUNK) as $chunk) {
            $orders = PancakeOrder::counted()->whereIn('phone_key', $chunk)->toBase()->get(['id', 'phone_key', 'seller_name', 'ordered_on']);

            foreach ($orders as $order) {
                $phone = (string) $order->phone_key;
                if (PancakeOrder::isCrdAccount($order->seller_name, $crdAccounts)) {
                    $soldByCrd[$phone] = true;
                }
                $when = [(string) $order->ordered_on, (int) $order->id];
                if (! isset($latest[$phone]) || $when > $latest[$phone][0]) {
                    $latest[$phone] = [$when, (string) $order->seller_name];
                }
            }
        }

        $cras = [];
        foreach ($latest as $phone => [, $seller]) {
            if (! isset($soldByCrd[$phone]) && isset($craOf[$seller])) {
                $cras[(string) $phone] = $craOf[$seller];
            }
        }

        return $cras;
    }

    /**
     * The FSA accounts set for exclusive CRAs who are still active and on the Exclusive Lead Tracker.
     *
     * @return array<string, int> seller name => CRA id
     */
    public function accounts(): array
    {
        $active = LeadGenerator::cras()->whereNotNull('exclusive_from')->pluck('id')->all();

        return ExclusiveFsaAccount::whereIn('user_id', $active)->orderBy('id')->pluck('user_id', 'seller_name')->all();
    }
}
