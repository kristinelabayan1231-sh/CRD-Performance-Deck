<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What was typed in a customer list's search box: a name, contact number, order # or product.
 */
class CustomerSearch
{
    public readonly string $term;

    /** The number typed, after a leading 0 / 63, the way numbers are stored. */
    public readonly string $number;

    public function __construct(?string $term)
    {
        $this->term = trim((string) $term);
        $this->number = (string) preg_replace('/^(63|0)/', '', (string) preg_replace('/\D/', '', $this->term));
    }

    public function isEmpty(): bool
    {
        return $this->term === '';
    }

    /**
     * A number is only searched from 4 digits, so a short one doesn't match nearly everyone.
     */
    public function hasNumber(): bool
    {
        return strlen($this->number) >= 4;
    }

    /**
     * The term for a LIKE match, anywhere in the text.
     */
    public function like(): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $this->term).'%';
    }

    /**
     * Just the matching rows of a list that keeps the customer's number first and the order id at $orderAt.
     *
     * @template TRow of array
     *
     * @param  list<TRow>  $rows
     * @return list<TRow>
     */
    public function filter(array $rows, int $orderAt): array
    {
        if ($this->isEmpty()) {
            return $rows;
        }

        $matches = $this->matcher();

        return array_values(array_filter($rows, fn (array $row) => $matches((string) $row[0], (string) $row[$orderAt])));
    }

    /**
     * A check for a customer's number and an order id. Names and products are looked up once here, in the tables the
     * lists take them from; numbers and order ids are checked on the row itself.
     *
     * @return Closure(string, string): bool
     */
    private function matcher(): Closure
    {
        $customers = DB::table('logistics_orders')->whereLike('customer_name', $this->like(), caseSensitive: false)
            ->pluck('phone_key')->filter()->flip()->all();
        $orders = [];
        foreach (['leads' => 'product_name', 'delivered_orders' => 'product_raw', 'logistics_orders' => 'product'] as $table => $product) {
            $matched = DB::table($table)
                ->where(fn (Builder $query) => $query->whereLike('customer_name', $this->like(), caseSensitive: false)->orWhereLike($product, $this->like(), caseSensitive: false))
                ->pluck('order_id');
            foreach ($matched as $orderId) {
                $orders[$orderId] = true;
            }
        }

        return fn (string $number, string $orderId) => isset($customers[$number])
            || isset($orders[$orderId])
            || ($this->hasNumber() && str_contains($number, $this->number))
            || stripos($orderId, $this->term) !== false;
    }
}
