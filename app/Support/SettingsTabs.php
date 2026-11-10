<?php

namespace App\Support;

use App\Models\User;

/**
 * The Settings sub tabs and the permission each needs. The Settings layout lists them, the sidebar shows Settings to
 * anyone who can open one, and /settings opens the first one they can. Add a new Settings page here.
 */
class SettingsTabs
{
    /**
     * Group => route name => [label, permission], in the order the Settings nav lists them.
     *
     * @var array<string, array<string, array{0: string, 1: string}>>
     */
    public const GROUPS = [
        'Personal' => [
            'settings.spiels.index' => ['My Spiels', 'segmentation.view'],
        ],
        'Products' => [
            'settings.product-consumption.index' => ['Product Consumption', 'product_consumption.view'],
            'settings.product-stage.index' => ['Testing Products', 'product_consumption.view'],
            'settings.product-qty.index' => ['Product Qty', 'product_qty.manage'],
            'settings.age-bracket.index' => ['Age Bracket', 'product_consumption.view'],
        ],
        'Pancake' => [
            'settings.pancake-pages.index' => ['Pancake Pages', 'pancake_pages.manage'],
            'settings.pancake-accounts.index' => ['Pancake Accounts', 'pancake_accounts.manage'],
        ],
        'Goals' => [
            'settings.sales-goals.index' => ['Sales Goals', 'sales_goals.manage'],
            'settings.working-date.index' => ['Working Date', 'sales_goals.manage'],
        ],
        'Modules' => [
            'settings.segmentation-options.index' => ['Segmentation Tracker', 'segmentation_options.manage'],
            'settings.niches.index' => ['Niche', 'niches.manage'],
            'settings.daily-is.index' => ['Daily I.S.', 'daily_is_products.manage'],
            'settings.call-recordings.index' => ['Call Recordings', 'call_recordings.manage'],
        ],
        'System' => [
            'settings.connections.index' => ['Connections', 'connections.check'],
            'settings.api-keys.index' => ['API Keys', 'api_keys.manage'],
        ],
    ];

    /**
     * Every permission that opens at least one Settings tab.
     *
     * @return list<string>
     */
    public static function permissions(): array
    {
        return array_values(array_unique(array_merge(...array_map(
            fn (array $tabs) => array_column($tabs, 1),
            array_values(self::GROUPS),
        ))));
    }

    /**
     * The tabs $user can open, by group; groups with none left out.
     *
     * @return array<string, array<string, array{0: string, 1: string}>>
     */
    public static function for(User $user): array
    {
        return array_filter(array_map(
            fn (array $tabs) => array_filter($tabs, fn (array $tab) => $user->can($tab[1])),
            self::GROUPS,
        ));
    }

    /**
     * The route /settings opens for $user: their first tab, the shared ones before My Spiels; null when they have none.
     */
    public static function landingFor(User $user): ?string
    {
        $tabs = self::for($user);
        $personal = $tabs['Personal'] ?? [];
        unset($tabs['Personal']);

        return array_key_first(array_merge(...array_values($tabs), ...[$personal]));
    }
}
