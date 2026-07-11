<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Precomputed Customers-tab dashboard stats (top spenders, customers per
 * page, top products) for the current week/month/year. Computing this live
 * from Pancake means scanning every order in the period — a month alone
 * took 54s in testing, a year far more — so it's only ever built by the
 * pancake:sync-customer-dashboard scheduled command, never on a web
 * request; the Customers page just reads whatever's cached here.
 */
class CustomerDashboardSnapshot extends Model
{
    protected $fillable = ['period', 'period_key', 'computed_at', 'payload'];

    protected $casts = [
        'computed_at' => 'datetime',
        'payload' => 'array',
    ];

    public static function find(string $period, string $periodKey): ?self
    {
        return static::where('period', $period)->where('period_key', $periodKey)->first();
    }
}
