<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Wave 1.5, step 2: give every team-scoped commerce table a `store_id`, derived
 * from the `team_id` it already carries.
 *
 * The scope that follows reads `store_id`, and a scope cannot precede the data
 * it reads. This is the data.
 *
 * The plan expected a constant — a single-store deployment has one store, so
 * every row gets it. Deriving from `team_id` instead costs nothing and is
 * correct on a deployment that already has several teams, where a constant
 * would hand one merchant's rows to another. Where there is one team the two
 * approaches produce the same thing.
 *
 * Rows whose `team_id` is null keep a null `store_id`. They belong to nobody
 * rather than to whoever sorts first — the same rule the API write guard uses,
 * and the opposite of the `default(1)` that produced the mess wave 2 is
 * unpicking.
 */
return new class extends Migration
{
    /**
     * The commerce tables carrying `team_id`, from the two migrations that added
     * it. `teams`, `team_user`, `team_invitations` and `stores` are excluded:
     * their `team_id` is the membership graph itself, which is Team-grained by
     * definition and has no store.
     */
    private const TABLES = [
        'product_categories', 'products', 'payment_methods', 'customers', 'wishlists',
        'orders', 'coupons', 'groups', 'product_reviews', 'downloadable_products',
        'images', 'cart_items', 'collections', 'invoices', 'product_rating',
    ];

    public function up(): void
    {
        $this->giveEveryTeamAStore();

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'team_id')) {
                continue;
            }

            if (! Schema::hasColumn($table, 'store_id')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    // Nullable and unconstrained, deliberately. A row with no
                    // team has no store, and a foreign key would force a
                    // default — which is exactly the mistake being unpicked.
                    $blueprint->unsignedBigInteger('store_id')->nullable()->after('team_id')->index();
                });
            }

            DB::table($table)
                ->whereNotNull('team_id')
                ->whereNull('store_id')
                ->update([
                    'store_id' => DB::raw('(select min(id) from stores where stores.team_id = '.$table.'.team_id)'),
                ]);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'store_id')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('store_id');
                });
            }
        }
    }

    /**
     * One store per team, so the derivation above has something to find.
     *
     * The first team already has one — the initial-channel migration created it.
     * The stores made here have no channel, so no hostname resolves to them:
     * a merchant whose storefront has not been configured is unreachable rather
     * than served from somebody else's domain.
     */
    private function giveEveryTeamAStore(): void
    {
        if (! Schema::hasTable('teams')) {
            return;
        }

        $now = now();

        DB::table('teams')
            ->whereNotIn('id', DB::table('stores')->whereNotNull('team_id')->pluck('team_id'))
            ->orderBy('id')
            ->each(function ($team) use ($now) {
                DB::table('stores')->insert([
                    'team_id' => $team->id,
                    'name' => $team->name,
                    'slug' => Str::slug($team->name).'-'.$team->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }
};
