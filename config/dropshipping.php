<?php

return [
    'suppliers' => [
        'supplier1' => [
            'name' => 'Supplier One',
            'description' => 'Main dropshipping supplier',
            'auth' => [
                'type' => 'api_key',
                'header' => 'X-API-Key',
                'key' => env('SUPPLIER1_API_KEY', ''),
            ],
            'endpoints' => [
                'availability' => env('SUPPLIER1_API_URL', 'https://api.supplier1.com').'/products/availability',
                'orders' => env('SUPPLIER1_API_URL', 'https://api.supplier1.com').'/orders',
                'tracking' => env('SUPPLIER1_API_URL', 'https://api.supplier1.com').'/orders/tracking',
            ],
        ],
        'supplier2' => [
            'name' => 'Supplier Two',
            'description' => 'Secondary dropshipping supplier',
            'auth' => [
                'type' => 'api_key',
                'header' => 'Authorization',
                'key' => 'Bearer '.env('SUPPLIER2_API_KEY', ''),
            ],
            'endpoints' => [
                'availability' => env('SUPPLIER2_API_URL', 'https://api.supplier2.com').'/check-stock',
                'orders' => env('SUPPLIER2_API_URL', 'https://api.supplier2.com').'/create-order',
                'tracking' => env('SUPPLIER2_API_URL', 'https://api.supplier2.com').'/track',
            ],
        ],
        'dropxl' => [
            'name' => 'DropXL',
            'description' => 'DropXL dropshipping supplier integration',
            'auth' => [
                'type' => 'api_key',
                'header' => 'Authorization',
                'key' => 'Bearer '.env('DROPXL_API_KEY', ''),
            ],
            'endpoints' => [
                'availability' => env('DROPXL_API_URL', 'https://api.dropxl.com').'/v1/products/availability',
                'orders' => env('DROPXL_API_URL', 'https://api.dropxl.com').'/v1/orders',
                'tracking' => env('DROPXL_API_URL', 'https://api.dropxl.com').'/v1/orders/track',
                'products' => env('DROPXL_API_URL', 'https://api.dropxl.com').'/v1/products',
                'categories' => env('DROPXL_API_URL', 'https://api.dropxl.com').'/v1/categories',
            ],
        ],
        'printful' => [
            'name' => 'Printful',
            'description' => 'Printful print-on-demand fulfilment',
            'auth' => [
                'type' => 'api_key',
                'header' => 'Authorization',
                'key' => 'Bearer '.env('PRINTFUL_API_TOKEN', ''),
            ],
            // Only for an account-level token; a store-scoped token names its store itself.
            'store_id' => env('PRINTFUL_STORE_ID'),
            'endpoints' => [
                'availability' => env('PRINTFUL_API_URL', 'https://api.printful.com').'/sync/variant',
                'orders' => env('PRINTFUL_API_URL', 'https://api.printful.com').'/orders',
                'tracking' => env('PRINTFUL_API_URL', 'https://api.printful.com').'/orders',
                'sync_products' => env('PRINTFUL_API_URL', 'https://api.printful.com').'/store/products',
            ],
        ],
    ],
];
