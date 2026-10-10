<?php

namespace App\Models;

use App\Services\LeadGenerator;
use App\Services\ProductCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * An FSD- or CRD-delivered order for the Customer Database: from the logistics retention
 * report, or from Pancake POS deliveries for the days before the report starts.
 */
#[Fillable(['order_id', 'team', 'source', 'customer_name', 'phone_number', 'phone_key', 'product', 'qty', 'non_crd', 'delivered_date'])]
class LogisticsOrder extends Model
{
    public const TEAM_FSD = 'fsd';

    public const TEAM_CRD = 'crd';

    public const SOURCE_LOGISTICS = 'logistics';

    public const SOURCE_POS = 'pos';

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'non_crd' => 'boolean',
            'delivered_date' => 'immutable_date',
        ];
    }

    /**
     * First delivery day the Customer Database covers (config customers.delivered_from).
     */
    public static function coveredFrom(): string
    {
        return CarbonImmutable::parse(config('customers.delivered_from'))->toDateString();
    }

    /**
     * Deliveries the Customer Database covers.
     */
    public function scopeCovered(Builder $query): Builder
    {
        return $query->where('delivered_date', '>=', self::coveredFrom());
    }

    /**
     * Save delivered orders from customers.delivered_from on. Orders already saved are left as they are.
     *
     * @param  list<array{order_id: string, team: string, source?: string, customer_name: string, phone_number: string, product: string, qty: ?int, delivered_date: string}>  $rows
     */
    public static function remember(array $rows): int
    {
        $now = now();
        $saved = 0;
        $from = self::coveredFrom();
        $catalog = new ProductCatalog;

        collect($rows)
            ->map(fn (array $row) => [
                ...$row,
                'source' => $row['source'] ?? self::SOURCE_LOGISTICS,
                'phone_key' => LeadGenerator::normalizePhone($row['phone_number']),
                // Only non-CRD products (e.g. NutriLay): left out of the Customer Database.
                'non_crd' => $catalog->onlyNonCrdText($row['product'] ?? null),
            ])
            ->filter(fn (array $row) => $row['order_id'] !== '' && $row['delivered_date'] >= $from && $row['phone_key'] !== '')
            ->map(fn (array $row) => [...$row, 'created_at' => $now, 'updated_at' => $now])
            ->chunk(1000)
            ->each(function (Collection $chunk) use (&$saved) {
                $saved += static::insertOrIgnore($chunk->values()->all());
            });

        return $saved;
    }
}
