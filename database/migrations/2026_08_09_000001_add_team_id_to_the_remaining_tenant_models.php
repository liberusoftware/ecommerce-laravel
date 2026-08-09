<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last eight models that declare tenancy and had nowhere to store it.
 *
 * These were left without a column on purpose while `IsTenantModel` was a
 * `team()` relation and nothing else: nothing read them through a panel or a
 * scope, and eight columns nothing writes and nothing filters are eight columns
 * of decoration.
 *
 * **The trait now writes the key**, so that reasoning expires. A model that
 * declares tenancy and cannot store it is no longer a dormant wrong claim — it
 * is an insert that fails on a column that is not there, which is #958 with the
 * blame moved one file over. Claim and schema have to agree in whichever
 * direction is true, and now the claim is the true one.
 *
 * These are the **roots**: merchant-owned, with no tenant-owned parent to
 * inherit an owner from. The twenty models whose owner *is* their parent's lost
 * the trait instead, and are deliberately absent here — copying `team_id` onto
 * every child table is the blanket migration this plan has spent a wave
 * unpicking.
 *
 * Nullable and with **no default**, like every tenant key here now. Null means
 * nobody could say, which is a true statement about a row created by a console
 * command; a default answers on the row's behalf, and that is the fault the
 * whole correction is about.
 */
return new class extends Migration
{
    private const TABLES = [
        'ab_tests',
        'cart_recovery_campaigns',
        'customer_groups',
        'customer_segments',
        'inventory_locations',
        'recommendation_rules',
        'seo_settings',
        'taxonomy_categories',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'team_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'team_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropForeign(['team_id']);
                $blueprint->dropColumn('team_id');
            });
        }
    }
};
