<?php

namespace App\Support;

use App\Models\Lead;
use App\Models\PancakeEngagement;
use App\Models\PancakeOrder;
use App\Services\LogisticsRetention;

/**
 * A stamp of the data behind the dashboard and the header's order issues: it changes whenever a
 * Pancake, lead or logistics sync (or a CRA's lead update) saves something, so an open dashboard
 * knows to reload.
 */
class DashboardVersion
{
    public static function current(): string
    {
        return md5(implode('|', [
            PancakeOrder::max('updated_at'),
            PancakeEngagement::max('updated_at'),
            Lead::max('updated_at'),
            LogisticsRetention::fetchedAt()?->toIso8601String(),
        ]));
    }
}
