<?php

namespace App\Models;

use App\Support\PancakeAccounts;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A Pancake POS order, kept for Segmentation Productivity and Conversion Breakdown.
 */
#[Fillable([
    'pancake_order_id', 'ordered_on', 'ordered_at', 'seller_pancake_id', 'seller_name', 'customer_name',
    'phone_number', 'phone_key', 'status', 'status_name', 'total_price', 'items', 'non_crd', 'page_name', 'tags', 'conversion_type',
])]
class PancakeOrder extends Model
{
    /** Pancake POS statuses that don't count as an order: 6 canceled, 7 deleted. */
    public const NOT_COUNTED_STATUSES = [6, 7];

    /** conversion_type values: orders tagged "CRD - BROADCAST" or "CRD - SEGMENTATION". */
    public const BROADCAST = 'broadcast';

    public const SEGMENTATION = 'segmentation';

    protected function casts(): array
    {
        return [
            'ordered_on' => 'immutable_date',
            'ordered_at' => 'datetime',
            'status' => 'integer',
            'total_price' => 'decimal:2',
            'shecom_sales' => 'decimal:2',
            'tags' => 'array',
            'items' => 'array',
            'non_crd' => 'boolean',
        ];
    }

    /**
     * The CRA's sale: Shecom's amount (without the child TSD row) once synced, else Pancake's total.
     */
    public function sales(): float
    {
        return (float) ($this->shecom_sales ?? $this->total_price);
    }

    /**
     * The CRD team's Pancake seller accounts (Settings → Pancake Accounts) as staff keys.
     *
     * @return list<string>
     */
    public static function crdAccounts(): array
    {
        return PancakeAccounts::crdKeys();
    }

    /**
     * Whether a seller (staff key) is one of the CRD team's accounts ($accounts: crdAccounts(), when already read).
     *
     * @param  list<string>|null  $accounts
     */
    public static function isCrdAccount(?string $sellerName, ?array $accounts = null): bool
    {
        return $sellerName !== null && in_array($sellerName, $accounts ?? self::crdAccounts(), true);
    }

    /**
     * Whether this order counts as a confirmed order (not canceled or deleted). Gross sales counts every status.
     */
    public function isCounted(): bool
    {
        return $this->status === null || ! in_array($this->status, self::NOT_COUNTED_STATUSES, true);
    }

    /**
     * Orders with a CRD product: leaves out those whose products are all non-CRD (e.g. NutriLay only).
     */
    public function scopeCrdProducts(Builder $query): Builder
    {
        return $query->where('non_crd', false);
    }

    public function scopeCounted(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('status')->orWhereNotIn('status', self::NOT_COUNTED_STATUSES));
    }

    /**
     * Which CRD conversion a set of POS tag ids makes the order; segmentation wins when both are there.
     *
     * @param  list<int>  $tagIds
     */
    public static function conversionTypeFor(array $tagIds): ?string
    {
        return match (true) {
            in_array(config('segmentation.conversion_tags.segmentation'), $tagIds, true) => self::SEGMENTATION,
            in_array(config('segmentation.conversion_tags.broadcast'), $tagIds, true) => self::BROADCAST,
            default => null,
        };
    }
}
