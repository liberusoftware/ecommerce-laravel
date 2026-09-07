<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A promo code is unique to the merchant who issued it, not to the installation.
 *
 * `coupons.code` and `discounts.code` were globally unique, which reads like a
 * correctness constraint and is really a land grab: the first merchant to issue
 * `SUMMER10` takes that code from every other merchant on the installation, and
 * they are told so by a database error on a form that had no way of knowing.
 *
 * The *reads* were fixed in wave 1.5 — `Coupon` is store-scoped, so a code
 * entered on one storefront cannot find another merchant's coupon. The index
 * grain was left behind, and this is it.
 *
 * **The grain differs because the models differ.** Coupons are store-scoped:
 * two storefronts of the same merchant are two shops, and a code that works in
 * one is not automatically live in the other. Discounts are team-scoped and
 * deliberately not store-scoped yet (see `StoreBackfillTest`), so team is the
 * finest grain their schema can express today. When a discount takes a
 * `store_id`, this constraint moves with it.
 *
 * Null owners collide freely, because SQL treats NULLs as distinct in a unique
 * index. That is the right behaviour and not an oversight: a row nothing could
 * attribute is not sellable — nothing resolves a store for it — so two of them
 * sharing a code costs nothing, and the alternative is a constraint that stops
 * a seeder mid-run over rows no shopper can reach.
 */
return new class extends Migration
{
    /** The column each table's code is unique *within*. */
    private const GRAIN = [
        'coupons' => 'store_id',
        'discounts' => 'team_id',
    ];

    public function up(): void
    {
        foreach (self::GRAIN as $table => $owner) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $owner)) {
                continue;
            }

            $this->dropTheGlobalUnique($table);

            Schema::table($table, function (Blueprint $blueprint) use ($owner) {
                $blueprint->unique([$owner, 'code']);
            });
        }
    }

    /**
     * The create migrations no longer declare it, so on a database built from
     * scratch there is nothing here to drop. A database built before that edit
     * still carries the index, and leaving it would keep the exact constraint
     * this migration exists to remove — silently, since the composite would go
     * on beside it and look like the job was done.
     */
    private function dropTheGlobalUnique(string $table): void
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['unique'] && $index['columns'] === ['code']) {
                Schema::table($table, function (Blueprint $blueprint) use ($index) {
                    $blueprint->dropIndex($index['name']);
                });
            }
        }
    }

    /**
     * The global unique is not restored. Once two merchants hold the same code
     * — the whole point of this migration — recreating it fails, and a `down()`
     * that only works if nobody used the feature is worse than one that says so.
     */
    public function down(): void
    {
        foreach (self::GRAIN as $table => $owner) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $owner)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($owner) {
                $blueprint->dropUnique([$owner, 'code']);
            });
        }
    }
};
