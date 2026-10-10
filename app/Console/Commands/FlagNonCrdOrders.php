<?php

namespace App\Console\Commands;

use App\Services\CustomerDatabase;
use App\Services\ProductCatalog;
use Illuminate\Console\Command;

class FlagNonCrdOrders extends Command
{
    protected $signature = 'products:flag-non-crd';

    protected $description = 'Tag saved orders whose products are all "Not a CRD product" (e.g. NutriLay only) so leads, sales and the Customer Database leave them out';

    public function handle(ProductCatalog $catalog): int
    {
        $flagged = $catalog->flagNonCrdOrders();
        CustomerDatabase::flushCache();

        $this->info("Tagged {$flagged['pancake']} Pancake orders and {$flagged['logistics']} deliveries as non-CRD.");

        return self::SUCCESS;
    }
}
