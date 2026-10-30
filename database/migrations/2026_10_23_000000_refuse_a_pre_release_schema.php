<?php

use App\Support\PreReleaseSchema;
use Illuminate\Database\Migrations\Migration;

/**
 * Before the first release, migrations were consolidated by editing them in
 * place, and a database migrated before that never picks the edits up. It
 * would keep, among other things, an order_items foreign key that deletes
 * order lines with their product. Rather than run on against that schema,
 * migrating stops here. See docs/INSTALLATION.md, "Rebuilding a development
 * database".
 */
return new class extends Migration
{
    /** A column each in-place edit added to a table that already existed. */
    private const MARKERS = [
        'products' => ['visibility', 'track_inventory'],
        'stores' => ['payment_gateways'],
        'shipping_methods' => ['store_id'],
        'cart_items' => ['configuration_key'],
        'order_items' => ['product_name'],
    ];

    public function up(): void
    {
        PreReleaseSchema::assertCurrent(self::MARKERS);
    }

    public function down(): void {}
};
