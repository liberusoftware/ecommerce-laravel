<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A coupon code is unique within the store that issued it, not the installation:
 * a global index let the first merchant to issue `SUMMER10` take it from all the
 * others. Null owners collide freely, as NULLs do in a unique index.
 */
return new class extends Migration
{
    /** The column each table's code is unique *within*. */
    private const GRAIN = [
        'coupons' => 'store_id',
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
