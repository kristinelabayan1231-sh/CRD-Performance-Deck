<?php

namespace App\Console\Commands;

use App\Services\CustomerDatabase;
use App\Services\ProductCatalog;
use Illuminate\Console\Command;

class FlagUnlistedOrders extends Command
{
    protected $signature = 'products:flag-unlisted';

    protected $description = 'Tag saved orders with no product from Settings → Product Consumption so leads, sales and the Customer Database leave them out, and remove their leads nobody has worked on yet';

    public function handle(ProductCatalog $catalog): int
    {
        $removed = $catalog->removeUntouchedUnlistedLeads();
        $flagged = $catalog->flagUnlistedOrders();
        CustomerDatabase::flushCache();

        $this->info("Removed {$removed} untouched leads. Tagged {$flagged['pancake']} Pancake orders and {$flagged['logistics']} deliveries with no listed product.");

        return self::SUCCESS;
    }
}
