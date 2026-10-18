<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Installation
    |--------------------------------------------------------------------------
    |
    | How this installation operates, chosen once by whoever deploys it:
    | 'single' (one merchant), 'saas' (merchants run their own stores) or
    | 'marketplace' (sellers share a marketplace).
    |
    | Whether a team may run several stores is a platform setting changed
    | under Admin > Platform settings; the env value below only seeds it.
    |
    */

    'installation' => [
        'mode' => env('ECOMMERCE_MODE', 'single'),
        'multiple_stores_per_team' => (bool) env('ECOMMERCE_MULTIPLE_STORES_PER_TEAM', false),
    ],
    /*
    |--------------------------------------------------------------------------
    | Tax Settings
    |--------------------------------------------------------------------------
    |
    | Each store sets its own tax display and country in its settings. These
    | seed existing stores on upgrade and apply off a storefront.
    |
    */

    'display_prices_with_tax' => env('TAX_DISPLAY_PRICES_WITH_TAX', false),

    // Store's own location — the rate used when showing tax-inclusive prices to a
    // visitor whose address is not yet known (product/cart pages).
    'store_country' => env('STORE_COUNTRY', 'US'),
    'store_state' => env('STORE_STATE'),

    /*
    |--------------------------------------------------------------------------
    | Currency Settings
    |--------------------------------------------------------------------------
    |
    | Each store has its own currency. This seeds the platform default given
    | to new stores.
    |
    */

    'default_currency' => env('DEFAULT_CURRENCY', 'USD'),

    /*
    |--------------------------------------------------------------------------
    | Loyalty Program Settings
    |--------------------------------------------------------------------------
    |
    | Enable or disable the loyalty points and rewards program.
    |
    */

    'loyalty_enabled' => env('LOYALTY_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Stock Notification Settings
    |--------------------------------------------------------------------------
    |
    | Configure stock notification behavior.
    |
    */

    'stock_notifications' => [
        'enabled' => env('STOCK_NOTIFICATIONS_ENABLED', true),
        'send_immediately' => env('STOCK_NOTIFICATIONS_IMMEDIATE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pre-order Settings
    |--------------------------------------------------------------------------
    |
    | Configure pre-order functionality.
    |
    */

    'preorders' => [
        'enabled' => env('PREORDERS_ENABLED', true),
        'charge_upfront_default' => env('PREORDERS_CHARGE_UPFRONT', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Wholesale Settings
    |--------------------------------------------------------------------------
    |
    | Configure B2B wholesale functionality.
    |
    */

    'wholesale' => [
        'enabled' => env('WHOLESALE_ENABLED', true),
        'requires_approval' => env('WHOLESALE_REQUIRES_APPROVAL', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Refund Settings
    |--------------------------------------------------------------------------
    |
    | Configure refund and return policies.
    |
    */

    'refunds' => [
        'enabled' => env('REFUNDS_ENABLED', true),
        'auto_restock' => env('REFUNDS_AUTO_RESTOCK', true),
        'return_window_days' => env('REFUNDS_RETURN_WINDOW_DAYS', 30),
    ],
];
